<?php
require 'RedBack.php';

$rb = &DB_RedBack::factory('socket');
$rb->__setDebug();

if (!$rb->open('rangi:8401', 'EXMOD:Employee', 'rbadmin', 'redback')) {
  echo implode("\n", $rb->__getError()) ."\n";
}

var_dump($rb->__Debug_Data);
?>
