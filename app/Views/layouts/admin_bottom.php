        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php // SweetAlert2 antes de app.js: app.js lo usa para los avisos y las confirmaciones. ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php // Scripts que solo necesita una pantalla (el controlador los pone en $scripts_extra).
      // Los de CDN llevan integrity: si el archivo cambiara, el navegador no lo ejecuta. ?>
<?php foreach (($scripts_extra ?? []) as $script): ?>
<script src="<?= e($script['src']) ?>"<?php if (!empty($script['integrity'])): ?> integrity="<?= e($script['integrity']) ?>" crossorigin="anonymous"<?php endif; ?>></script>
<?php endforeach; ?>
</body>
</html>
