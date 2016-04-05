<?php
/** 
 * Configuración básica de WordPress.
 *
 * Este archivo contiene las siguientes configuraciones: ajustes de MySQL, prefijo de tablas,
 * claves secretas, idioma de WordPress y ABSPATH. Para obtener más información,
 * visita la página del Codex{@link http://codex.wordpress.org/Editing_wp-config.php Editing
 * wp-config.php} . Los ajustes de MySQL te los proporcionará tu proveedor de alojamiento web.
 *
 * This file is used by the wp-config.php creation script during the
 * installation. You don't have to use the web site, you can just copy this file
 * to "wp-config.php" and fill in the values.
 *
 * @package WordPress
 */

// ** Ajustes de MySQL. Solicita estos datos a tu proveedor de alojamiento web. ** //
/** El nombre de tu base de datos de WordPress */
define('DB_NAME', 'versionpress');

/** Tu nombre de usuario de MySQL */
define('DB_USER', 'root');

/** Tu contraseña de MySQL */
define('DB_PASSWORD', '12345678');

/** Host de MySQL (es muy probable que no necesites cambiarlo) */
define('DB_HOST', 'localhost');

/** Codificación de caracteres para la base de datos. */
define('DB_CHARSET', 'utf8mb4');

/** Cotejamiento de la base de datos. No lo modifiques si tienes dudas. */
define('DB_COLLATE', '');

/**#@+
 * Claves únicas de autentificación.
 *
 * Define cada clave secreta con una frase aleatoria distinta.
 * Puedes generarlas usando el {@link https://api.wordpress.org/secret-key/1.1/salt/ servicio de claves secretas de WordPress}
 * Puedes cambiar las claves en cualquier momento para invalidar todas las cookies existentes. Esto forzará a todos los usuarios a volver a hacer login.
 *
 * @since 2.6.0
 */
define('AUTH_KEY', 'SWX]6j9(gQ{$8kDfbG?40hmbm(qYtr<)qouI%BdiI51(6slr5%(Ez},[1$mwv?IA');
define('SECURE_AUTH_KEY', 'NR.I]oZU,zW(l6Ze() hO!0]+7?sY@U[My2.m{jX_KAw$y.W.Z.r k`e|aMXp.79');
define('LOGGED_IN_KEY', '1XI=u*4=H)H+5]=:>s7<}  ljc&AB|w!hGcw[%?!S`qQz!Tu]{$G+I+.HF}4+<J+');
define('NONCE_KEY', 'i3SASETDJA+(Ek)`LTEj|Ui6$Ov9T1#>`g}ZD -MV9:`uzR1KrNGL$0_<h{wH:be');
define('AUTH_SALT', '3P 7]N*P$o:N~=,&KCaB.]PPl}v}`|ay^I_Q{i5BN?lKNN+[uu iMliea$|KZ? /');
define('SECURE_AUTH_SALT', 'qJL-sqi}AFj&3A:MC9|$dQqD-QoL<IJoPk{uj~S}?&*1wV`3cCw;&y|79JzZ4GVR');
define('LOGGED_IN_SALT', '+@!Kh3]QwWEVPz})CD/]]/gAj?{O~NL!eD w<WYz9QJXd.I=XfeD6G2+z0n{3<ss');
define('NONCE_SALT', '/+L-|?VIRz-j>R1ewOld>^M0f(A[OWc2rN=imW=M&MD%:Bw.uo`&;p[uTNTtUZcU');

/**#@-*/

/**
 * Prefijo de la base de datos de WordPress.
 *
 * Cambia el prefijo si deseas instalar multiples blogs en una sola base de datos.
 * Emplea solo números, letras y guión bajo.
 */
$table_prefix  = 'wp_';


/**
 * Para desarrolladores: modo debug de WordPress.
 *
 * Cambia esto a true para activar la muestra de avisos durante el desarrollo.
 * Se recomienda encarecidamente a los desarrolladores de temas y plugins que usen WP_DEBUG
 * en sus entornos de desarrollo.
 */
define('WP_DEBUG', false);

/* ¡Eso es todo, deja de editar! Feliz blogging */

/** WordPress absolute path to the Wordpress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');

