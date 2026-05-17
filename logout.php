<?php
require "db/config.php";
unset($_SESSION['passenger_id'], $_SESSION['passenger_name']);
header("Location: index.php");
exit;
