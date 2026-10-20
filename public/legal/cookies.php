<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
render_header('Cookie Policy');
?>
<article class="legal wrap">
  <p class="kicker">Cookies</p>
  <h1>Cookie Policy</h1>
  <p>Quire uses one first-party session cookie named <code>QUIRESESSID</code>. It is marked HttpOnly and SameSite=Lax, and it is marked Secure when the site is served over HTTPS.</p>
  <p>The cookie holds the session that connects your uploads, the form-protection token, and, if you connect Google, a short-lived access token. It is not used for advertising. There is no analytics cookie in this application.</p>
  <p>Closing the browser ends the cookie. The server-side session and uploaded files are also removed by the retention job. Blocking cookies will stop uploads from being matched to your browser.</p>
</article>
<?php render_footer();