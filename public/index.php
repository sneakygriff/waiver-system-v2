<?php
// Local dev convenience: the app has no root landing page (entry points are
// admin.php / api.php / w.php). Redirect the root to the admin UI so hitting
// http://localhost:8080/ doesn't return a bare 403.
header('Location: /admin.php');
exit;
