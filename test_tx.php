<?php
$pdo_n = new PDO('mysql:host=192.168.11.16;dbname=mdlapps_moodleadmin;charset=utf8mb4', 'mdlapps', 'RmnPbT78');
$pdo_o = new PDO('mysql:host=dbmoodle.met.dmz;dbname=mdlapps_moodleadmin;charset=utf8mb4', 'moodle', 'RmnPbT78');
$t = '9JM431007K040812H';
echo "Results New: " . ($pdo_n->query("SELECT id FROM results WHERE transaction_id='$t'")->fetchColumn() ? "YES\n" : "NO\n");
echo "Results Old: " . ($pdo_o->query("SELECT id FROM results WHERE transaction_id='$t'")->fetchColumn() ? "YES\n" : "NO\n");
