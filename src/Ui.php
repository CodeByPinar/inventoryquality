<?php

namespace GlpiPlugin\Inventoryquality;

use Glpi\Application\View\TemplateRenderer;
use Html;

/**
 * Ekran yardımcıları: üst gezinme, şablon çizimi, tarih biçimi, liste bağlantıları.
 */
final class Ui
{
    /** @return array<string,array{label:string,url:string,icon:string}> */
    public static function nav(): array
    {
        $b = Menu::base();
        $n = [
            'overview' => ['label' => __('Genel Bakış', 'inventoryquality'), 'url' => $b . '/index.php', 'icon' => 'ti ti-gauge'],
            'finding'  => ['label' => __('Bulgular', 'inventoryquality'), 'url' => $b . '/finding.php', 'icon' => 'ti ti-alert-triangle'],
            'correction' => ['label' => __('Düzeltmeler', 'inventoryquality'), 'url' => $b . '/correction.php', 'icon' => 'ti ti-pencil-check'],
            'exception'  => ['label' => __('İstisnalar', 'inventoryquality'), 'url' => $b . '/exception.php', 'icon' => 'ti ti-clock-pause'],
            'rule'     => ['label' => __('Kurallar', 'inventoryquality'), 'url' => $b . '/rule.php', 'icon' => 'ti ti-list-check'],
            'jobs'     => ['label' => __('İşler', 'inventoryquality'), 'url' => $b . '/jobs.php', 'icon' => 'ti ti-clock-play'],
        ];
        $mine = count(CorrectionService::waitingFor((int) \Session::getLoginUserID()));
        if ($mine > 0) {
            $n['correction']['label'] .= " ($mine)";
        }
        if (Rights::has(Rights::CONFIG)) {
            $n['config'] = ['label' => __('Ayarlar', 'inventoryquality'), 'url' => $b . '/config.php', 'icon' => 'ti ti-settings'];
        }
        return $n;
    }

    public static function header(string $title, string $option): void
    {
        Html::header($title, Menu::base() . '/index.php', 'tools', Menu::class, $option);
    }

    /** @param array<string,mixed> $vars */
    public static function render(string $template, array $vars, string $active = ''): void
    {
        TemplateRenderer::getInstance()->display('@inventoryquality/' . $template, $vars + [
            'nav' => self::nav(), 'active' => $active, 'base' => Menu::base(),
        ]);
    }

    public static function dt(?string $d): string
    {
        return $d ? (string) Html::convDateTime($d) : '';
    }

    /**
     * Bulgular listesi bağlantısı (GLPI arama ölçütleriyle).
     * @param list<array{0:int,1:string,2:string}> $criteria [alan, aramatürü, değer]
     */
    public static function findingsUrl(array $criteria): string
    {
        $q = ['reset' => 'reset'];
        foreach ($criteria as $i => [$field, $type, $value]) {
            $q['criteria'][$i] = ['link' => 'AND', 'field' => $field, 'searchtype' => $type, 'value' => $value];
        }
        return Menu::base() . '/finding.php?' . http_build_query($q);
    }

    public static function back(string $url): void
    {
        Html::redirect($url);
    }

    public static function msg(string $text, bool $error = false): void
    {
        \Session::addMessageAfterRedirect(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false, $error ? ERROR : INFO);
    }

    public static function resultBadge(string $r): string
    {
        $map = [
            RuleEvaluator::PASS    => ['bg-success-lt', __('Uygun', 'inventoryquality')],
            RuleEvaluator::FAIL    => ['bg-danger-lt', __('Uygunsuz', 'inventoryquality')],
            RuleEvaluator::UNKNOWN => ['bg-orange-lt', __('Değerlendirilemedi', 'inventoryquality')],
            RuleEvaluator::NA      => ['bg-secondary-lt', __('Uygulanmaz', 'inventoryquality')],
        ];
        [$c, $l] = $map[$r] ?? ['bg-secondary-lt', $r];
        return "<span class='badge $c'>" . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    public static function scanStatus(string $s): string
    {
        $map = [
            'running'   => ['bg-azure-lt', __('Sürüyor', 'inventoryquality')],
            'completed' => ['bg-success-lt', __('Tamamlandı', 'inventoryquality')],
            'partial'   => ['bg-orange-lt', __('Kısmi', 'inventoryquality')],
            'failed'    => ['bg-danger-lt', __('Hatalı', 'inventoryquality')],
        ];
        [$c, $l] = $map[$s] ?? ['bg-secondary-lt', $s];
        return "<span class='badge $c'>" . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
