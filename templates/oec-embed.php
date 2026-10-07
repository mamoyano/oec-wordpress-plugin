<?php
/**
 * Ficha incrustada (/formacion-incrustada/...): la página "Formación" sin encabezado ni pie del
 * tema, para mostrarla dentro de un <iframe> (ver oec_is_embed() en oec-main.php).
 */
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>html,body{margin:0!important;padding:0!important;background:#fff!important}</style>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();
while (have_posts()) {
    the_post();
    the_content();
}
wp_footer();
?>
</body>
</html>
