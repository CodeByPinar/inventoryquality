<?php

/**
 * Kural tanımı: hedef alan, değerler / önkoşul, durum kapsamı, önem, ağırlık, süre, sorumlu, destek kaydı politikası.
 * "Önizle" örnek kayıtlar üzerinde beklenen etkiyi gösterir — bulgu, değerlendirme ya da destek kaydı ÜRETMEZ.
 * "Yayınla" yeni sürüm oluşturur (önceki sürümler geçmişte kalır); etkin kuralda kapsam yeni sürümle yeniden taranır.
 */

use GlpiPlugin\Inventoryquality\AuditLog;
use GlpiPlugin\Inventoryquality\Catalog;
use GlpiPlugin\Inventoryquality\Config;
use GlpiPlugin\Inventoryquality\Db;
use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Menu;
use GlpiPlugin\Inventoryquality\QualityCalculator;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Rule;
use GlpiPlugin\Inventoryquality\RuleTemplates;
use GlpiPlugin\Inventoryquality\ScanRunner;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

$id = (int) ($_REQUEST['id'] ?? 0);
$rule = Db::row(Rule::getTable(), ['id' => $id]);
if (!$rule || !Rule::canAccess($rule)) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
}
$self = Menu::base() . '/rule.form.php?id=' . $id;
// Alt birimden üst birimin (alt birimlere uygulanan) kuralı GÖRÜLÜR ama yalnız kuralın kendi birimine erişimi olan değiştirir.
$canEdit = Rights::has(Rights::RULES) && Session::haveAccessToEntity((int) $rule['entities_id']);
$itemtype = (string) $rule['itemtype'];
$template = (string) $rule['template'];
$current = Rule::version((int) $rule['ruleversions_id']);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
    if (!$canEdit) {
        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    try {
        if (isset($_POST['publish'])) {
            $def = RuleTemplates::build($template, $itemtype, $_POST);
            $vid = Rule::publish($id, $def, (int) ($_POST['severity'] ?? 2), (int) ($_POST['weight'] ?? 1), trim((string) ($_POST['comment'] ?? '')));
            Ui::msg(sprintf(__('Sürüm v%d yayınlandı.', 'inventoryquality'), (int) (Rule::version($vid)['version'] ?? 0))
                . ((int) $rule['is_active'] === 1 ? ' ' . __('Kapsam yeni sürümle yeniden taranacak.', 'inventoryquality') : ' ' . __('Kural pasif; etkinleştirince taranır.', 'inventoryquality')));
            Ui::back($self);
        }
        if (isset($_POST['activate']) || isset($_POST['deactivate'])) {
            Rule::setActive($id, isset($_POST['activate']));
            Ui::msg(isset($_POST['activate'])
                ? __('Kural etkinleştirildi; kapsam taranmak üzere kuyruğa alındı.', 'inventoryquality')
                : __('Kural pasifleştirildi; aktif bulguları "Kapsam dışı" oldu (çözülmüş sayılmaz).', 'inventoryquality'));
            Ui::back($self);
        }
        if (isset($_POST['rename'])) {
            global $DB;
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name !== '') {
                $DB->update(Rule::getTable(), ['name' => mb_substr($name, 0, 255), 'comment' => (string) ($_POST['rule_comment'] ?? ''), 'date_mod' => Db::now()], ['id' => $id]);
                AuditLog::write('rule_rename', Rule::class, $id, (int) $rule['entities_id'], ['name' => $rule['name']], ['name' => $name]);
            }
            Ui::back($self);
        }
        if (isset($_POST['remove'])) {
            if (!Rule::canRemove($id)) {
                throw new InvalidArgumentException(__('Bulgusu ya da değerlendirmesi olan kural silinemez; pasifleştirin.', 'inventoryquality'));
            }
            (new Rule())->delete(['id' => $id], true);
            AuditLog::write('rule_delete', Rule::class, $id, (int) $rule['entities_id'], ['code' => $rule['code']]);
            Ui::msg(__('Kural silindi.', 'inventoryquality'));
            Ui::back(Menu::base() . '/rule.php');
        }
    } catch (InvalidArgumentException $e) {
        if (!isset($_POST['preview']) && !isset($_POST['refresh'])) {
            Ui::msg($e->getMessage(), true);
            Ui::back($self);
        }
    }
}

// Form durumu: POST (önizle / alanı uygula) ya da yayındaki sürüm.
$def = $current['def'] ?? [];
if ($isPost) {
    $in = $_POST;
} else {
    $in = [
        'field'        => $def['target'] ?? '',
        'values'       => $def['assert']['values'] ?? [],
        'pre_field'    => $def['precondition']['field'] ?? '',
        'pre_op'       => $def['precondition']['op'] ?? 'in',
        'pre_values'   => $def['precondition']['values'] ?? [],
        'scope_states' => $def['scope']['states'] ?? [],
        'due_days'     => $def['policy']['due_days'] ?? 0,
        'groups_id'    => $def['policy']['groups_id'] ?? 0,
        'users_id'     => $def['policy']['users_id'] ?? 0,
        'ticket'       => $def['policy']['ticket'] ?? 'inherit',
        'severity'     => $current['severity'] ?? RuleTemplates::all()[$template]['severity'] ?? 2,
        'weight'       => $current['weight'] ?? RuleTemplates::all()[$template]['weight'] ?? 1,
        'days'         => $def['assert']['days'] ?? ($template === 'attestation' ? 180 : 30),
        'only_dynamic' => $def ? !empty($def['precondition']) : true,
        'attest_fields' => $template === 'attestation' && !empty($def['target']) ? Catalog::attestList((string) $def['target']) : [],
        'approval'     => $def['policy']['approval']['level'] ?? 'none',
        'appr1_group'  => $def['policy']['approval']['steps'][1]['groups_id'] ?? 0,
        'appr1_user'   => $def['policy']['approval']['steps'][1]['users_id'] ?? 0,
        'appr1_mode'   => $def['policy']['approval']['steps'][1]['mode'] ?? 'any',
        'appr2_group'  => $def['policy']['approval']['steps'][2]['groups_id'] ?? 0,
        'appr2_mode'   => $def['policy']['approval']['steps'][2]['mode'] ?? 'any',
        'allow_self'   => !empty($def['policy']['approval']['allow_self']),
    ];
}
$targets = RuleTemplates::targetFields($template, $itemtype);
$cat = Catalog::fields($itemtype);
$field = (string) ($in['field'] ?? '');
if (!isset($targets[$field])) {
    $field = (string) array_key_first($targets);
}
$preField = (string) ($in['pre_field'] ?? '');
if (!isset($cat[$preField])) {
    $preField = isset($cat['core:states_id']) ? 'core:states_id' : (string) array_key_first($cat);
}
$scopeEnt = (int) $rule['entities_id'];
$valueWidget = static function (string $name, ?array $fd, array $vals) use ($scopeEnt, $rule): string {
    if (!$fd) {
        return '';
    }
    if (in_array($fd['datatype'], ['fk', 'user'], true)) {
        // Çoklu seçimde ad "[]" ile bitmeli (GLPI eklemez; aksi halde tarayıcı yalnız son değeri gönderir).
        $o = ['name' => $name . '[]', 'value' => array_values(array_map('intval', $vals)), 'multiple' => true, 'display' => false, 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive']];
        if ($fd['datatype'] === 'user') {
            $o['right'] = 'all';
            return User::dropdown($o);
        }
        return Dropdown::show($fd['ref_itemtype'], $o);
    }
    if ($fd['datatype'] === 'bool') {
        return Dropdown::showYesNo($name . '[]', (int) ($vals[0] ?? 0), -1, ['display' => false]);
    }
    return "<textarea class='form-control' name='" . htmlspecialchars($name, ENT_QUOTES) . "[]' rows='4'>" . htmlspecialchars(implode("\n", array_map('strval', $vals)), ENT_QUOTES) . '</textarea>'
        . "<div class='form-text'>" . __('Her satıra bir değer.', 'inventoryquality') . '</div>';
};

$preview = null;
$previewError = '';
if ($isPost && (isset($_POST['preview']) || isset($_POST['refresh']))) {
    try {
        $pdef = RuleTemplates::build($template, $itemtype, $in);
        if (isset($_POST['preview'])) {
            $p = ScanRunner::preview(['itemtype' => $itemtype, 'entities' => Rule::scopeEntities($scopeEnt, (bool) $rule['is_recursive']), 'v' => ['def' => $pdef]], Config::get('preview_limit'));
            $rows = [];
            foreach ($p['sample'] as $s) {
                $obs = [];
                foreach ($s['observed'] as $k => $v) {
                    $obs[] = ($cat[$k]['label'] ?? $k) . ': ' . $v;
                }
                $rows[] = ['url' => $itemtype::getFormURLWithID((int) $s['items_id']), 'name' => $s['name'] !== '' ? $s['name'] : '#' . $s['items_id'], 'badge' => Ui::resultBadge($s['result']), 'observed' => implode('; ', $obs)];
            }
            $preview = ['counts' => $p['counts'], 'scanned' => $p['scanned'], 'rows' => $rows, 'describe' => RuleTemplates::describe($pdef, $itemtype)];
        }
    } catch (InvalidArgumentException $e) {
        $previewError = $e->getMessage();
    }
}

$versions = [];
foreach (Rule::versions($id) as $v) {
    $d = Db::decode($v['definition']);
    $versions[] = ['version' => (int) $v['version'], 'date' => Ui::dt($v['date_creation']), 'user' => getUserName((int) $v['users_id']),
        'severity' => Finding::severityLabel((int) $v['severity']), 'weight' => (int) $v['weight'], 'describe' => RuleTemplates::describe($d, $itemtype),
        'comment' => (string) $v['comment'], 'current' => (int) $v['id'] === (int) $rule['ruleversions_id']];
}
$stats = QualityCalculator::compute(['rules_id' => $id]);
$sev = [];
foreach (RuleTemplates::SEVERITIES as $k => $l) {
    $sev[$k] = __($l, 'inventoryquality');
}
$ops = [];
foreach (['in' => __('şunlardan biri', 'inventoryquality'), 'not_in' => __('şunlardan biri değil', 'inventoryquality'), 'eq' => '=', 'neq' => '≠',
          'empty' => __('boş', 'inventoryquality'), 'not_empty' => __('dolu', 'inventoryquality')] as $k => $l) {
    if (in_array($k, $cat[$preField]['ops'] ?? [], true)) {
        $ops[$k] = $l;
    }
}

Ui::header(Rule::getTypeName(1) . ' ' . $rule['code'], 'rule');
Ui::render('rule_form.html.twig', [
    'rule'          => $rule,
    'self'          => $self,
    'tpl'           => RuleTemplates::all()[$template] ?? ['label' => $template, 'help' => '', 'code' => ''],
    'template'      => $template,
    'type_label'    => class_exists($itemtype) ? $itemtype::getTypeName(1) : $itemtype,
    'entity'        => Dropdown::getDropdownName('glpi_entities', $scopeEnt),
    'can_edit'      => $canEdit,
    'current'       => $current ? ['version' => (int) $current['version'], 'describe' => RuleTemplates::describe($current['def'], $itemtype)] : null,
    'targets'       => array_map(static fn($d) => $d['label'], $targets),
    'field'         => $field,
    'fields_all'    => array_map(static fn($d) => $d['label'], $cat),
    'pre_field'     => $preField,
    'pre_op'        => (string) ($in['pre_op'] ?? 'in'),
    'ops'           => $ops,
    'w_values'      => $template === 'valueset' ? $valueWidget('values', $cat[$field] ?? null, (array) ($in['values'] ?? [])) : '',
    'w_pre_values'  => $template === 'conditional' ? $valueWidget('pre_values', $cat[$preField] ?? null, (array) ($in['pre_values'] ?? [])) : '',
    'w_states'      => isset($cat['core:states_id']) ? Dropdown::show('State', ['name' => 'scope_states[]', 'value' => array_map('intval', (array) ($in['scope_states'] ?? [])), 'multiple' => true, 'display' => false, 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive']]) : '',
    'w_group'       => Group::dropdown(['name' => 'groups_id', 'value' => (int) ($in['groups_id'] ?? 0), 'condition' => ['is_assign' => 1], 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive'], 'display' => false]),
    'w_user'        => User::dropdown(['name' => 'users_id', 'value' => (int) ($in['users_id'] ?? 0), 'right' => 'all', 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive'], 'display' => false]),
    'severities'    => $sev,
    'severity'      => (int) ($in['severity'] ?? 2),
    'weight'        => (int) ($in['weight'] ?? 1),
    'due_days'      => (int) ($in['due_days'] ?? 0),
    'ticket'        => (string) ($in['ticket'] ?? 'inherit'),
    'comment'       => (string) ($in['comment'] ?? ''),
    'days'          => (int) ($in['days'] ?? 30),
    'only_dynamic'  => !empty($in['only_dynamic']),
    'attestable'    => RuleTemplates::attestableFields($itemtype),
    'attest_fields' => array_map('strval', (array) ($in['attest_fields'] ?? [])),
    'allows_corr'   => RuleTemplates::allowsCorrection($template),
    'approval'      => (string) ($in['approval'] ?? 'none'),
    'appr1_mode'    => (string) ($in['appr1_mode'] ?? 'any'),
    'appr2_mode'    => (string) ($in['appr2_mode'] ?? 'any'),
    'allow_self'    => !empty($in['allow_self']),
    'w_appr1_group' => Group::dropdown(['name' => 'appr1_group', 'value' => (int) ($in['appr1_group'] ?? 0), 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive'], 'display' => false]),
    'w_appr1_user'  => User::dropdown(['name' => 'appr1_user', 'value' => (int) ($in['appr1_user'] ?? 0), 'right' => 'all', 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive'], 'display' => false]),
    'w_appr2_group' => Group::dropdown(['name' => 'appr2_group', 'value' => (int) ($in['appr2_group'] ?? 0), 'entity' => $scopeEnt, 'entity_sons' => (bool) $rule['is_recursive'], 'display' => false]),
    'policy_label'  => $current ? RuleTemplates::approvalLabel($current['def']['policy']['approval'] ?? []) : '',
    'preview'       => $preview,
    'preview_error' => $previewError,
    'preview_limit' => Config::get('preview_limit'),
    'versions'      => $versions,
    'stats'         => $stats + ['score_txt' => QualityCalculator::pct($stats['score'], __('Hesaplanamadı', 'inventoryquality')), 'cov_txt' => QualityCalculator::pct($stats['coverage'], __('Kapsam yok', 'inventoryquality'))],
    'open'          => countElementsInTable(Finding::getTable(), ['rules_id' => $id, 'status' => Finding::ACTIVE]),
    'findings_url'  => Ui::findingsUrl([[4, 'contains', (string) $rule['code']]]),
    'can_remove'    => Rule::canRemove($id),
], 'rule');
Html::footer();
