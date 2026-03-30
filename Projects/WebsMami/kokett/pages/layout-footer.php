</main>
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-newsletter">
      <p><?= t('footer.newsletter') ?></p>
      <form id="newsletter-form" class="newsletter-form">
        <input type="email" name="email" placeholder="tu@email.com" required>
        <button type="submit"><?= t('footer.subscribe') ?></button>
      </form>
      <p id="newsletter-msg" class="hidden"></p>
    </div>
    <div class="footer-links">
      <a href="/policies/legal-notice">Aviso Legal</a>
      <a href="/policies/privacy-policy">Privacidad</a>
      <a href="/policies/shipping-policy">Envíos</a>
      <a href="/policies/contact-information">Contacto</a>
    </div>
    <p class="footer-copy">&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Todos los derechos reservados.</p>
  </div>
</footer>
<script src="/assets/js/main.js"></script>
</body>
</html>
