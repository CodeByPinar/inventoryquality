<?php

namespace GlpiPlugin\Inventoryquality;

use CommonITILObject;
use ITILFollowup;
use ITILSolution;
use Ticket;

/**
 * Destek kaydı köprüsü.
 *
 *  - Model: aynı birim + aynı varlık + aynı sorumlu (grup ya da kişi) için TEK açık düzeltme kaydı; bir kayıt birden
 *    çok bulguyu taşır. Farklı birimler birleştirilmez. Birimde "yalnız bulgu" modu (varsayılan) ya da kuralda
 *    "destek kaydı açma" seçiliyse kayıt açılmaz. Atanmamış bulgu için kayıt açılmaz (Atama bekliyor kuyruğu).
 *  - Tüm bağlı aktif bulgular PASS ile çözülünce kayda çözüm eklenir; GLPI'nin çözüm onayı / otomatik kapanış
 *    politikası korunur (doğrudan Kapalı yapılmaz). Kapsam dışı / devredilen bulgularla kapanışta metin
 *    "düzeltildi" demez.
 *  - Kayıt erken kapatılırsa (çözüm bizden gelmeden) bulgular açık kalır; tutarsızlık denetim izine yazılır ve
 *    politika gereği eski bağlantı saklanarak yeni takip kaydı açılır.
 *  - Aynı varlık için eşzamanlı iki işçi aynı anda kayıt açmasın diye veritabanı adlı kilidi kullanılır.
 */
final class TicketBridge
{
    public const LINKS = Install::P . 'ticketlinks';

    public static function syncAsset(string $itemtype, int $itemsId): void
    {
        global $DB;
        $lock = 'iq_t_' . substr(hash('sha256', $itemtype . ':' . $itemsId), 0, 40);
        $got = (int) ($DB->doQuery('SELECT GET_LOCK(' . $DB->quoteValue($lock) . ', 10) AS l')->fetch_assoc()['l'] ?? 0);
        if ($got !== 1) {
            throw new \RuntimeException('Destek kaydı kilidi alınamadı; yeniden denenecek.');
        }
        try {
            self::doSync($itemtype, $itemsId);
        } finally {
            $DB->doQuery('SELECT RELEASE_LOCK(' . $DB->quoteValue($lock) . ')');
        }
    }

    private static function doSync(string $itemtype, int $itemsId): void
    {
        global $DB;
        $findings = [];
        foreach ($DB->request(['FROM' => Finding::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $itemsId]]) as $f) {
            $findings[(int) $f['id']] = $f;
        }
        if (!$findings) {
            return;
        }
        $links = iterator_to_array($DB->request(['FROM' => self::LINKS, 'WHERE' => ['findings_id' => array_keys($findings), 'is_open' => 1]]), false);
        $byTicket = [];
        foreach ($links as $l) {
            $byTicket[(int) $l['tickets_id']][] = $l;
        }

        // 1–2) Açık bağlantılar: erken kapanma, çözülen / kapsam dışı / devredilen bulgular.
        $prev = [];
        foreach ($byTicket as $tid => $tl) {
            $t = new Ticket();
            $exists = $t->getFromDB($tid) && (int) $t->fields['is_deleted'] === 0;
            $closed = !$exists || in_array((int) $t->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true);
            if ($closed) {
                $still = array_filter($tl, static fn($l) => isset($findings[(int) $l['findings_id']]) && in_array($findings[(int) $l['findings_id']]['status'], Finding::ACTIVE, true));
                foreach ($tl as $l) {
                    self::closeLink((int) $l['id'], $still ? __('Destek kaydı bulgu çözülmeden kapatıldı', 'inventoryquality') : __('Destek kaydı kapandı', 'inventoryquality'));
                }
                if ($still) {
                    $f0 = $findings[(int) reset($still)['findings_id']];
                    AuditLog::write('ticket_closed_early', Ticket::class, $tid, (int) $f0['entities_id'], null,
                        ['findings' => array_map(static fn($l) => (int) $l['findings_id'], array_values($still))],
                        __('Destek kaydı kapatıldı ama bağlı bulgular güncel veride hâlâ uygunsuz; yeni takip kaydı açılacak.', 'inventoryquality'));
                    foreach ($still as $l) {
                        $prev[(string) $l['group_key']] = $tid;
                    }
                }
                continue;
            }
            $remaining = [];
            $closing = [];
            foreach ($tl as $l) {
                $f = $findings[(int) $l['findings_id']] ?? null;
                if ($f === null) {
                    $closing[] = [$l, null, 'gone'];
                } elseif (in_array($f['status'], Finding::ACTIVE, true) && self::groupKey($f) === (string) $l['group_key'] && (int) $f['cycle'] === (int) $l['cycle']) {
                    $remaining[] = $l;
                } elseif ($f['status'] === Finding::RESOLVED && (int) $f['cycle'] === (int) $l['cycle']) {
                    $closing[] = [$l, $f, 'resolved'];
                } elseif (in_array($f['status'], Finding::ACTIVE, true) && (int) $f['cycle'] === (int) $l['cycle']) {
                    $closing[] = [$l, $f, 'reassigned'];
                } else {
                    $closing[] = [$l, $f, (string) ($f['status'] ?? 'gone')];
                }
            }
            if (!$closing) {
                continue;
            }
            $allResolved = !array_filter($closing, static fn($c) => $c[2] !== 'resolved');
            $content = self::closingText($closing, $itemtype);
            if ($remaining) {
                self::followup($tid, $content);
            } else {
                $s = new ITILSolution();
                $ok = $s->add(['itemtype' => Ticket::class, 'items_id' => $tid, 'content' => ($allResolved
                    ? '<p><strong>' . self::e(__('Doğrulandı: bağlı tüm veri kalitesi bulguları güncel veride kuralı sağladı.', 'inventoryquality')) . '</strong></p>'
                    : '<p><strong>' . self::e(__('Bu kayıttaki bulgular düzeltme dışında bir nedenle kapandı; "düzeltildi" sayılmadı.', 'inventoryquality')) . '</strong></p>') . $content]);
                if (!$ok) {
                    throw new \RuntimeException("Destek kaydına çözüm eklenemedi (#$tid).");
                }
            }
            foreach ($closing as [$l, $f, $why]) {
                self::closeLink((int) $l['id'], self::whyLabel($why));
            }
            AuditLog::write($remaining ? 'ticket_followup' : 'ticket_solution', Ticket::class, $tid, (int) ($t->fields['entities_id'] ?? 0), null,
                ['closed_findings' => array_map(static fn($c) => (int) $c[0]['findings_id'], $closing), 'all_resolved' => $allResolved]);
        }

        // 3) Aktif + atanmış bulgular için kayıt aç / mevcut kayda ekle.
        $groups = [];
        foreach ($findings as $f) {
            if (!in_array($f['status'], Finding::ACTIVE, true) || $f['assign_state'] !== 'assigned') {
                continue;
            }
            if (EntityConfig::effective((int) $f['entities_id'])['ticket_mode'] !== 'grouped') {
                continue;
            }
            $v = Rule::version((int) $f['ruleversions_id']);
            if (($v['def']['policy']['ticket'] ?? 'inherit') === 'off') {
                continue;
            }
            $groups[self::groupKey($f)][] = $f;
        }
        foreach ($groups as $key => $fs) {
            $open = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => self::LINKS, 'WHERE' => ['group_key' => $key, 'is_open' => 1], 'LIMIT' => 1])->current();
            if ($open) {
                $tid = (int) $open['tickets_id'];
                $new = [];
                foreach ($fs as $f) {
                    if (countElementsInTable(self::LINKS, ['findings_id' => (int) $f['id'], 'tickets_id' => $tid, 'cycle' => (int) $f['cycle']]) === 0) {
                        $new[] = $f;
                    }
                }
                if ($new) {
                    foreach ($new as $f) {
                        self::link((int) $f['id'], $tid, (int) $f['cycle'], $key);
                    }
                    self::followup($tid, '<p>' . self::e(__('Yeni veri kalitesi bulgusu eklendi:', 'inventoryquality')) . '</p>' . self::findingTable($new, $itemtype));
                    AuditLog::write('ticket_append', Ticket::class, $tid, (int) $fs[0]['entities_id'], null, ['findings' => array_map(static fn($f) => (int) $f['id'], $new)]);
                }
                continue;
            }
            $tid = self::createTicket($itemtype, $itemsId, $fs, $prev[$key] ?? 0);
            foreach ($fs as $f) {
                self::link((int) $f['id'], $tid, (int) $f['cycle'], $key);
            }
        }
    }

    /** @param list<array<string,mixed>> $fs */
    private static function createTicket(string $itemtype, int $itemsId, array $fs, int $previous): int
    {
        $f0 = $fs[0];
        $eid = (int) $f0['entities_id'];
        $cfg = EntityConfig::effective($eid);
        $content = '<p>' . self::e(sprintf(__('Envanter veri kalitesi kontrolü bu varlıkta %d kuralın sağlanmadığını tespit etti. Düzeltmeden sonra kayıt otomatik olarak yeniden kontrol edilir; bulgular yalnız güncel veride kural sağlandığında çözülür.', 'inventoryquality'), count($fs))) . '</p>';
        if ($previous > 0) {
            $content .= '<p>' . self::e(sprintf(__('Önceki takip kaydı #%d bulgular çözülmeden kapatıldı; bulgular hâlâ geçerli.', 'inventoryquality'), $previous)) . '</p>';
        }
        $content .= self::findingTable($fs, $itemtype);
        $input = [
            'name'        => mb_substr(sprintf(__('Envanter veri kalitesi - %s', 'inventoryquality'), AssetAdapter::label($itemtype, $itemsId)), 0, 255),
            'content'     => $content,
            'entities_id' => $eid,
            'type'        => Ticket::DEMAND_TYPE,
            'items_id'    => [$itemtype => [$itemsId]],
        ];
        if ((int) $f0['groups_id_assign'] > 0) {
            $input['_groups_id_assign'] = (int) $f0['groups_id_assign'];
        } elseif ((int) $f0['users_id_assign'] > 0) {
            $input['_users_id_assign'] = (int) $f0['users_id_assign'];
        }
        if ((int) $cfg['users_id_requester'] > 0) {
            $input['_users_id_requester'] = (int) $cfg['users_id_requester'];
        }
        $dues = array_filter(array_map(static fn($f) => (string) $f['due_date'], $fs));
        if ($dues) {
            $input['time_to_resolve'] = min($dues);
        }
        $t = new Ticket();
        $tid = (int) $t->add($input);
        if ($tid <= 0) {
            throw new \RuntimeException('Destek kaydı oluşturulamadı.');
        }
        AuditLog::write('ticket_create', Ticket::class, $tid, $eid, null, ['findings' => array_map(static fn($f) => (int) $f['id'], $fs), 'previous' => $previous]);
        return $tid;
    }

    /** Destek kaydı değişti (çözüldü / kapandı / silindi): bağlı varlıkları yeniden eşitle. */
    public static function checkTicket(int $ticketId): void
    {
        global $DB;
        $assets = [];
        foreach ($DB->request([
            'SELECT' => [Finding::getTable() . '.itemtype', Finding::getTable() . '.items_id'],
            'FROM'   => self::LINKS,
            'INNER JOIN' => [Finding::getTable() => ['ON' => [Finding::getTable() => 'id', self::LINKS => 'findings_id']]],
            'WHERE'  => [self::LINKS . '.tickets_id' => $ticketId, self::LINKS . '.is_open' => 1],
        ]) as $r) {
            $assets[$r['itemtype'] . ':' . $r['items_id']] = [(string) $r['itemtype'], (int) $r['items_id']];
        }
        foreach ($assets as [$it, $id]) {
            self::syncAsset($it, $id);
        }
    }

    /**
     * Bulgunun açık destek kaydına takip notu (düzeltme / onay olayları). Bildirim hatası asıl işlemi geri almaz.
     */
    public static function note(int $findingsId, string $text): void
    {
        global $DB;
        try {
            $l = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => self::LINKS, 'WHERE' => ['findings_id' => $findingsId, 'is_open' => 1], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
            if ($l) {
                self::followup((int) $l['tickets_id'], '<p>' . self::e($text) . '</p>');
            }
        } catch (\Throwable $e) {
            error_log('inventoryquality takip notu eklenemedi: ' . $e->getMessage());
        }
    }

    public static function groupKey(array $f): string
    {
        $who = (int) $f['groups_id_assign'] > 0 ? 'G' . (int) $f['groups_id_assign'] : 'U' . (int) $f['users_id_assign'];
        return hash('sha256', implode('|', [(int) $f['entities_id'], $f['itemtype'], (int) $f['items_id'], $who]));
    }

    /** @return list<array<string,mixed>> bulgunun destek kaydı bağlantıları (yeniden eskiye) */
    public static function linksOf(int $findingsId): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::LINKS, 'WHERE' => ['findings_id' => $findingsId], 'ORDER' => 'id DESC']), false);
    }

    private static function link(int $findingsId, int $ticketId, int $cycle, string $key): void
    {
        Db::insertIgnore(self::LINKS, ['findings_id' => $findingsId, 'tickets_id' => $ticketId, 'cycle' => $cycle, 'group_key' => $key, 'is_open' => 1, 'date_creation' => Db::now()]);
    }

    private static function closeLink(int $linkId, string $note): void
    {
        global $DB;
        $DB->update(self::LINKS, ['is_open' => 0, 'close_note' => mb_substr($note, 0, 255), 'date_closed' => Db::now()], ['id' => $linkId]);
    }

    private static function followup(int $tid, string $html): void
    {
        $fu = new ITILFollowup();
        if (!$fu->add(['itemtype' => Ticket::class, 'items_id' => $tid, 'content' => $html, 'is_private' => 0])) {
            throw new \RuntimeException("Destek kaydına takip eklenemedi (#$tid).");
        }
    }

    /** @param list<array<string,mixed>> $fs */
    private static function findingTable(array $fs, string $itemtype): string
    {
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/inventoryquality/front/finding.form.php?id=';
        $h = '<table border="1" cellpadding="4"><tr><th>' . self::e(__('Kural', 'inventoryquality')) . '</th><th>' . self::e(__('Beklenen', 'inventoryquality'))
            . '</th><th>' . self::e(__('Mevcut durum', 'inventoryquality')) . '</th><th>' . self::e(__('Kontrol', 'inventoryquality'))
            . '</th><th>' . self::e(__('Hedef tarih', 'inventoryquality')) . '</th></tr>';
        foreach ($fs as $f) {
            $rule = Db::row(Rule::getTable(), ['id' => (int) $f['rules_id']]) ?? ['code' => '?', 'name' => ''];
            $v = Rule::version((int) $f['ruleversions_id']);
            $d = Db::decode($f['detail']);
            $obs = [];
            foreach ((array) ($d['observed'] ?? []) as $k => $s) {
                $obs[] = (Catalog::get($itemtype, (string) $k)['label'] ?? $k) . ': ' . $s;
            }
            $h .= '<tr><td><a href="' . self::e($base . (int) $f['id']) . '">' . self::e($rule['code'] . ' · ' . $rule['name']) . '</a></td>'
                . '<td>' . self::e($v ? RuleTemplates::describe($v['def'], $itemtype) : '') . '</td>'
                . '<td>' . self::e(implode('; ', $obs)) . '</td>'
                . '<td>' . self::e((string) $f['last_check']) . '</td>'
                . '<td>' . self::e((string) $f['due_date']) . '</td></tr>';
        }
        return $h . '</table>';
    }

    /** @param list<array{0:array,1:?array,2:string}> $closing */
    private static function closingText(array $closing, string $itemtype): string
    {
        $h = '<ul>';
        foreach ($closing as [$l, $f, $why]) {
            $rule = $f ? (Db::row(Rule::getTable(), ['id' => (int) $f['rules_id']]) ?? ['code' => '?']) : ['code' => '?'];
            $h .= '<li>' . self::e(sprintf('%s — %s', $rule['code'], self::whyLabel($why))) . ($f && $f['status_reason'] !== '' ? ' (' . self::e((string) $f['status_reason']) . ')' : '') . '</li>';
        }
        return $h . '</ul>';
    }

    private static function whyLabel(string $why): string
    {
        return match ($why) {
            'resolved'               => __('Doğrulandı (kural güncel veride sağlandı)', 'inventoryquality'),
            'reassigned'             => __('Başka sorumluya devredildi', 'inventoryquality'),
            Finding::OUT_OF_SCOPE    => __('Kapsam dışı — düzeltme sayılmadı', 'inventoryquality'),
            Finding::EXCEPTION       => __('İstisna — düzeltme sayılmadı', 'inventoryquality'),
            'gone'                   => __('Bulgu kaldırıldı', 'inventoryquality'),
            default                  => __('Bulgu bu kayıtta artık izlenmiyor', 'inventoryquality'),
        };
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
