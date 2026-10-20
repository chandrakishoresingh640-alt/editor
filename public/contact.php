<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
render_header('Contact', 'about');
$email = trim((string) config('contact_email'));
?>
<article class="legal wrap narrow">
  <p class="kicker">Contact</p>
  <h1>Write to the site owner.</h1>
  <?php if ($email !== ''): ?><p>You can also email <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</p><?php endif; ?>
  <form id="contact-form" class="card pad" method="post" action="<?= e(url_path('/api/contact.php')) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field" hidden><label>Company website<input name="company_website" tabindex="-1" autocomplete="off"></label></div>
    <div class="field"><label for="name">Name</label><input id="name" name="name" required autocomplete="name"></div>
    <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" required autocomplete="email"></div>
    <div class="field"><label for="message">Message</label><textarea id="message" name="message" required></textarea></div>
    <button class="btn" type="submit">Send message</button>
    <p id="contact-status" class="hint" role="status"></p>
  </form>
</article>
<?php render_footer(['/assets/js/contact.js']);