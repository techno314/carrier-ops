<?php

declare(strict_types=1);

/**
 * The admin panel.
 *
 * Its own file rather than a view of settings.php, which is what it used to
 * be. Two reasons. The panel keeps growing — accounts, carriers, uploads,
 * maintenance — and settings.php rendering something else entirely and then
 * exiting was a seam that only got worse. And every POST arriving here is
 * unambiguously an admin action, so the admin check happens once at the top
 * instead of settings.php sniffing action names for an `admin_` prefix and
 * re-checking rights it had already checked.
 *
 * It is also the door during maintenance. The closed sign no longer offers a
 * sign-in link — there is no reason to tell the public where the staff
 * entrance is — so an admin comes straight here instead. Being signed out is
 * fine: fc_require_user sends them to sign in with `next` pointing back, and
 * the maintenance guard lets both this page and the sign-in page through for
 * exactly that reason.
 */

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/admin.php';

$user = fc_require_user();

if ((int) $user['is_admin'] !== 1) {
    fc_fail(403, 'That is for admins.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fc_check_csrf();
    fc_handle_admin_post((string) ($_POST['action'] ?? ''), $user);
    fc_redirect(fc_url('admin.php'));
}

fc_head('Admin', 'settings');
fc_render_admin($user);
fc_foot();
