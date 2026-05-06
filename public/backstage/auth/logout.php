<?php
declare(strict_types=1);

unset(
    $_SESSION['user'],
    $_SESSION['token'],
    $_SESSION['expires_at'],
    $_SESSION['view_as_role']
);
session_regenerate_id(true);
header('Location: /backstage/login');
exit;
