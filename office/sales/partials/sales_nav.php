<?php
// Shared sales section tab navigation
// Requires: $sales_tab (string: 'pos'|'dk'|'ws'|'transfer'), $year, $month, $today
$_sn_y = $year ?? date('Y');
$_sn_m = $month ?? date('n');
$_sn_t = $today ?? date('Y-m-d');
$_sn_active = $sales_tab ?? 'pos';

$_tabs = [
    ['key'=>'pos',      'href'=>"daily_entry.php?date={$_sn_t}",                 'icon'=>'fa-cash-register',         'label'=>'POS Entry',    'color'=>'green'],
    ['key'=>'dk',       'href'=>"dk_entry.php?year={$_sn_y}&month={$_sn_m}",     'icon'=>'fa-motorcycle',            'label'=>'Delivery K',   'color'=>'blue'],
    ['key'=>'ws',       'href'=>"ws_entry.php?year={$_sn_y}&month={$_sn_m}",     'icon'=>'fa-boxes-stacked',         'label'=>'Whole Sale',   'color'=>'indigo'],
    ['key'=>'credit',   'href'=>"credit_entry.php?year={$_sn_y}&month={$_sn_m}", 'icon'=>'fa-file-invoice-dollar',   'label'=>'Credit Sale',  'color'=>'amber'],
    ['key'=>'transfer', 'href'=>"transfer.php?year={$_sn_y}&month={$_sn_m}",     'icon'=>'fa-arrow-right-arrow-left','label'=>'Store Transfers','color'=>'teal'],
];

$_color_map = [
    'green'  => ['active'=>'bg-green-600 text-white',  'hover'=>'hover:bg-green-50 hover:text-green-700'],
    'blue'   => ['active'=>'bg-blue-600 text-white',   'hover'=>'hover:bg-blue-50 hover:text-blue-700'],
    'indigo' => ['active'=>'bg-indigo-600 text-white', 'hover'=>'hover:bg-indigo-50 hover:text-indigo-700'],
    'amber'  => ['active'=>'bg-amber-600 text-white',  'hover'=>'hover:bg-amber-50 hover:text-amber-700'],
    'teal'   => ['active'=>'bg-teal-600 text-white',   'hover'=>'hover:bg-teal-50 hover:text-teal-700'],
];
?>
<div class="flex items-center gap-1 mb-5 bg-white rounded-xl shadow-sm border border-gray-100 p-1.5 overflow-x-auto">
  <?php foreach ($_tabs as $_tab):
    $is_active = ($_tab['key'] === $_sn_active);
    $c = $_color_map[$_tab['color']];
    $cls = $is_active
        ? $c['active'] . ' rounded-lg px-4 py-2 text-sm font-medium whitespace-nowrap'
        : 'text-gray-500 rounded-lg px-4 py-2 text-sm whitespace-nowrap ' . $c['hover'];
  ?>
  <a href="<?php echo $is_active ? '#' : $_tab['href']; ?>" class="<?php echo $cls; ?>">
    <i class="fa-solid <?php echo $_tab['icon']; ?> mr-1.5"></i><?php echo $_tab['label']; ?>
  </a>
  <?php endforeach; ?>
</div>
