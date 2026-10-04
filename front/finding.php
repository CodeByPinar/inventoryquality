<?php

/**
 * Bulgular — GLPI arama motoruyla liste: varlık, kural, önem, durum, sorumlu, hedef tarih, son kontrol filtreleri.
 * Liste, sayaç ve dışa aktarma GLPI'nin birim kısıtından geçer.
 */

use GlpiPlugin\Inventoryquality\Finding;
use GlpiPlugin\Inventoryquality\Rights;
use GlpiPlugin\Inventoryquality\Ui;

include('../../../inc/includes.php');

Session::checkRight(Rights::NAME, Rights::VIEW);

Ui::header(Finding::getTypeName(2), 'finding');
Ui::render('nav.html.twig', [], 'finding');
Search::show(Finding::class);
Html::footer();
