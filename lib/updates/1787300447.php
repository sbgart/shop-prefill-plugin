<?php

/**
 * @var waPlugin $this
 */
$path = $this->path.'/lib/config/db.php';
if (file_exists($path)) {
    $schema = include($path);
    $m = new waModel();
    $m->modifyColumn('value', $schema, null, 'shop_prefill_settings');
}
