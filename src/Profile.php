<?php

namespace GlpiPlugin\Inventoryquality;

use CommonGLPI;
use DbUtils;
use Html;
use ProfileRight;
use Session;

/**
 * Profil sekmesi "Envanter Veri Kalitesi": işlem başına ayrı yetkiler (bkz. Rights).
 * Kurulumda yapılandırma güncelleme yetkisi olan profiller tüm hakları alır, diğerleri 0 ile başlar;
 * yeniden kurulum verilmiş yetkileri değiştirmez.
 */
class Profile extends \Profile
{
    public static $rightname = 'profile';

    /** @return list<array{rights:array<int,string>,label:string,field:string}> */
    public static function getAllRights(): array
    {
        return [[
            'rights' => Rights::labels(),
            'label'  => __('Envanter Veri Kalitesi', 'inventoryquality'),
            'field'  => Rights::NAME,
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && (int) $item->getField('id') > 0) {
            return self::createTabEntry(__('Envanter Veri Kalitesi', 'inventoryquality'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            (new self())->showForm((int) $item->getID());
        }
        return true;
    }

    public function showForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);
        echo "<div class='firstbloc'>";
        if ($canedit && $openform) {
            echo "<form method='post' action='" . (new \Profile())->getFormURL() . "'>";
        }
        $profile = new \Profile();
        $profile->getFromDB($profiles_id);
        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Envanter Veri Kalitesi', 'inventoryquality'),
        ]);
        if ($canedit && $closeform) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }

    public static function ensureRight(int $profilesId, int $value): void
    {
        if ((new DbUtils())->countElementsInTable('glpi_profilerights', ['profiles_id' => $profilesId, 'name' => Rights::NAME]) > 0) {
            return;
        }
        (new ProfileRight())->add(['profiles_id' => $profilesId, 'name' => Rights::NAME, 'rights' => $value]);
        if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profilesId) {
            $_SESSION['glpiactiveprofile'][Rights::NAME] = $value;
        }
    }

    public static function addNewProfile(\Profile $prof): void
    {
        self::ensureRight((int) $prof->getID(), 0);
    }

    public static function install(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $super = [];
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $a) {
            if (((int) $a['rights'] & UPDATE) === UPDATE) {
                $super[(int) $a['profiles_id']] = true;
            }
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $p) {
            self::ensureRight((int) $p['id'], isset($super[(int) $p['id']]) ? Rights::ALL : 0);
        }
    }

    public static function uninstall(): void
    {
        (new ProfileRight())->deleteByCriteria(['name' => Rights::NAME]);
    }
}
