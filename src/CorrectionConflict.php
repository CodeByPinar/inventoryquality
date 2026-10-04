<?php

namespace GlpiPlugin\Inventoryquality;

/** Anlık görüntü / politika çakışması: yazma yapılmaz, güncel veriyle yeni öneri gerekir. */
final class CorrectionConflict extends \RuntimeException
{
}
