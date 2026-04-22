<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "INCLUDE START<br>";
require_once '../index.php';
echo "INCLUDE END<br>";
class_exists('PayPal') ? print "Class PayPal EXISTS" : print "Class PayPal NOT FOUND";
