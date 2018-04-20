<?php

/**
 * AIO Security Class
 * @category  Security
 * @author    Marco Cesarato <cesarato.developer@gmail.com>
 * @copyright Copyright (c) 2014-2018
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      https://github.com/marcocesarato/PHP-AIO-Security-Class
 * @version   0.2.7
 */

class Security
{
	// Config
	public static $basedir = __DIR__; // Project basedir where is located .htaccess
	public static $session_name = "XSESSID"; // Session cookie name
	public static $session_lifetime = 288000; // Session lifetime | default = 8 hours
	public static $session_regenerate_id = false; // Rengenerate session id
	public static $csrf_session = "_CSRFTOKEN"; // CSRF session token name
	public static $csrf_formtoken = "_FORMTOKEN"; // CSRF form token input name
	public static $headers_cache = true; // Enable header cache
	public static $headers_cache_days = 30; // Cache on NO HTML response (set 0 to disable)
	public static $escape_string = true; // If you use PDO I recommend to set this to false
	public static $scanner_path = "./*.php"; // Folder to scan at start and optionally the file extension
	public static $scanner_whitelist = array(); // Scan paths/files whitelist
	public static $clean_post_xss = true; // Remove XSS on post global
	public static $compress_output = true; // Compress output

	protected static $_SALT = "_SALT"; // Salt for encryptions => use setSalt($salt) or change it

	// Autostart
	public static $auto_session_manager = true; // Run session at start
	public static $auto_scanner = false; // Could have a bad performance impact and could detect false positive,
										 // then try the method secureScanPath before enable this. BE CAREFUL
	public static $auto_block_tor = true; // If you want block TOR clients
	public static $auto_clean_global = false; // Global clean at start
	public static $auto_antidos = true; // Block the client ip when there are too many requests

	// Error Template
	public static $error_callback = null; // Set a callback on errors
	public static $error_template = '<html><head><title>${ERROR_TITLE}</title></head><body>${ERROR_BODY}</body></html>';

	/**
	 * Security constructor.
	 * @param bool $API
	 */
	function __construct($API = false) {
		self::putInSafety($API);
	}

	/**
	 * Secure initialization
	 * @param bool $API
	 */
	public static function putInSafety($API = false) {

		if (!$API) {
			if (self::$auto_session_manager)
				self::secureSession();
			if (self::$auto_scanner)
				self::secureScan(self::$scanner_path);
			self::secureFormRequest();
			self::secureCSRF();
		}

		if (self::$auto_antidos)
			self::secureDOS();

		self::secureRequest();
		self::secureBlockBots();

		if (self::$auto_block_tor)
			self::secureBlockTor();

		self::saveUnsafeGlobals();
		if (self::$auto_clean_global) {
			self::cleanGlobals();
		}

		self::secureHijacking();
		self::headers($API);
		self::secureCookies();

	}

	/**
	 * Set Salt
	 * @param $salt
	 * @return bool
	 */
	public static function setSalt($salt) {
		self::$_SALT = $salt;
		return true;
	}

	/**
	 * Custom session name for prevent fast identification of php
	 */
	public static function secureSession() {
		self::unsetCookie('PHPSESSID');

		ini_set("session.cookie_httponly", true);
		ini_set("session.use_trans_sid", false);
		ini_set('session.use_only_cookies', true);
		ini_set("session.cookie_secure", self::checkHTTPS());
		ini_set("session.gc_maxlifetime", self::$session_lifetime);

		session_name(self::$session_name);
		session_start();
		if (self::$session_regenerate_id)
			session_regenerate_id(true);
	}

	/**
	 * Fix some elements on output buffer (to use with ob_start)
	 * @param $buffer
	 * @param string $type
	 * @param null $cache_days
	 * @param bool $compress
	 * @return string
	 */
	public static function output($buffer, $type = 'html', $cache_days = null, $compress = true) {

		if (self::$headers_cache) self::headersCache($cache_days);

		$compress_output = (self::$compress_output && $compress);

		if ($type = 'html' && self::isHTML($buffer)) {
			header("Content-Type: text/html");
			$buffer = self::secureHTML($buffer);
			if ($compress_output) $buffer = self::compressHTML($buffer);
		} elseif ($type == 'css') {
			header("Content-type: text/css");
			if ($compress_output) $buffer = self::compressCSS($buffer);
		} elseif ($type == 'csv') {
			header("Content-type: text/csv");
			header("Content-Disposition: attachment; filename=file.csv");
			if ($compress_output) $buffer = self::compressOutput($buffer);
		} elseif ($type == 'js' || $type == 'javascript') {
			header('Content-Type: application/javascript');
			if ($compress_output) $buffer = self::compressJS($buffer);
		} elseif ($type == 'json') {
			header('Content-Type: application/json');
			if ($compress_output) $buffer = self::compressOutput($buffer);
		} elseif ($type == 'xml') {
			header('Content-Type: text/xml');
			if ($compress_output) $buffer = self::compressHTML($buffer);
		} elseif ($type == 'text' || $type == 'txt') {
			header("Content-Type: text/plain");
			if ($compress_output) $buffer = self::compressOutput($buffer);
		} else {
			if ($compress_output) $buffer = self::compressOutput($buffer);
		}

		header('Content-Length: ' . strlen($buffer)); // For cache header

		return $buffer;
	}

	/**
	 * Security Headers
	 * @param bool $API
	 */
	public static function headers($API = false) {
		// Headers
		@header("Accept-Encoding: gzip, deflate");
		@header("Strict-Transport-Security: max-age=16070400; includeSubDomains; preload");
		@header("X-UA-Compatible: IE=edge,chrome=1");
		@header("X-XSS-Protection: 1; mode=block");
		@header("X-Frame-Options: sameorigin");
		@header("X-Content-Type-Options: nosniff");
		@header("X-Permitted-Cross-Domain-Policies: master-only");
		@header("Referer-Policy: origin");
		@header("X-Download-Options: noopen");
		if (!$API) @header("Access-Control-Allow-Methods: GET, POST");
		else @header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");

		header_remove("X-Powered-By");
		header_remove("Server");

		// Php settings
		ini_set('expose_php', 'off');
		ini_set('allow_url_fopen', 'off');
		ini_set('magic_quotes_gpc', 'off');
		ini_set('register_globals', 'off');
		ini_set('session.cookie_httponly', 'on');
		ini_set('session.use_only_cookies', 'on');
	}

	/**
	 * Cache Headers
	 * @param null $cache_days
	 */
	public static function headersCache($cache_days = null) {
		// Cache Headers
		$days_to_cache = (($cache_days == null) ? self::$headers_cache_days : $cache_days) * (60 * 60 * 24);
		$ts = gmdate("D, d M Y H:i:s", time() + $days_to_cache) . " GMT";
		@header("Expires: $ts");
		@header("Pragma" . ($days_to_cache > 0) ? "cache" : "no-cache");
		@header("Cache-Control: max-age=$days_to_cache, must-revalidate");
	}

	/**
	 * Security Cookies
	 */
	public static function secureCookies() {
		foreach ($_COOKIE as $key => $value) {
			//$value = self::clean(self::getCookie($key), false, false);
			//self::setCookie($key, $value, 0, '/; SameSite=Strict', null, false, true);
			if ($key != self::$session_name)
				setcookie($key, $value, 0, '/; SameSite=Strict', null, false, self::checkHTTPS());
		}
	}

	/**
	 * Check if the request is secure
	 */
	public static function secureRequest() {

		// Disable methods
		if (preg_match("/^(HEAD|TRACE|TRACK|DEBUG|OPTIONS)/i", $_SERVER['REQUEST_METHOD']))
			self::error(403, 'Permission denied!');

		// Check REQUEST_URI
		$_REQUEST_URI = urldecode($_SERVER['REQUEST_URI']);
		if (preg_match("/(<|%3C)([^s]*s)+cript.*(>|%3E)/i", $_REQUEST_URI) ||
			preg_match("/(<|%3C)([^e]*e)+mbed.*(>|%3E)/i", $_REQUEST_URI) ||
			preg_match("/(<|%3C)([^o]*o)+bject.*(>|%3E)/i", $_REQUEST_URI) ||
			preg_match("/(<|%3C)([^i]*i)+frame.*(>|%3E)/i", $_REQUEST_URI) ||
			preg_match("/(<|%3C)([^o]*o)+bject.*(>|%3E)/i", $_REQUEST_URI) ||
			preg_match("/base64_(en|de)code[^(]*\([^)]*\)/i", $_REQUEST_URI) ||
			preg_match("/(%0A|%0D|\\r|\\n)/", $_REQUEST_URI) ||
			preg_match("/union([^a]*a)+ll([^s]*s)+elect/i", $_REQUEST_URI))
			self::error(403, 'Permission denied!');

		// Check QUERY_STRING
		$_QUERY_STRING = urldecode($_SERVER['QUERY_STRING']);
		if (preg_match("/(<|%3C)([^s]*s)+cript.*(>|%3E)/i", $_QUERY_STRING) ||
			preg_match("/(<|%3C)([^e]*e)+mbed.*(>|%3E)/i", $_QUERY_STRING) ||
			preg_match("/(<|%3C)([^o]*o)+bject.*(>|%3E)/i", $_QUERY_STRING) ||
			preg_match("/(<|%3C)([^i]*i)+frame.*(>|%3E)/i", $_QUERY_STRING) ||
			preg_match("/(<|%3C)([^o]*o)+bject.*(>|%3E)/i", $_QUERY_STRING) ||
			preg_match("/base64_(en|de)code[^(]*\([^)]*\)/i", $_QUERY_STRING) ||
			preg_match("/(%0A|%0D|\\r|\\n)/i", $_QUERY_STRING) ||
			preg_match("/(\.\.\/|\.\.\\|%2e%2e%2f|%2e%2e\/|\.\.%2f|%2e%2e%5c)/i", $_QUERY_STRING) ||
			preg_match("/(;|<|>|'|\"|\)|%0A|%0D|%22|%27|%3C|%3E|%00).*(\*|union|select|insert|cast|set|declare|drop|update|md5|benchmark).*/i", $_QUERY_STRING) ||
			preg_match("/union([^a]*a)+ll([^s]*s)+elect/i", $_QUERY_STRING))
			self::error(403, 'Permission denied!');
	}


	/**
	 * Secure Form Request check if the referer is equal to the origin
	 */
	public static function secureFormRequest() {
		if ($_SERVER["REQUEST_METHOD"] == "POST") {
			$referer = $_SERVER["HTTP_REFERER"];
			if (!isset($referer) || strpos($_SERVER["SERVER_NAME"], $referer) != 0)
				self::error(403, 'Permission denied!');
		}
	}

	/**
	 * Compress generic output
	 * @param $buffer
	 * @return string
	 */
	public static function compressOutput($buffer) {
		ini_set("zlib.output_compression", "On");
		ini_set("zlib.output_compression_level", "9");
		return preg_replace(array('/\s+/'), array(' '), str_replace(array("\n", "\r", "\t"), '', $buffer));
	}

	/**
	 * Compress HTML
	 * @param $buf
	 * @return string
	 */
	public static function compressHTML($buffer) {
		ini_set("zlib.output_compression", "On");
		ini_set("zlib.output_compression_level", "9");
		if (self::isHTML($buffer)) {
			$pattern = "/<script[^>]*>(.*?)<\/script>/is";
			preg_match_all($pattern, $buffer, $matches, PREG_SET_ORDER, 0);
			foreach ($matches as $match){
				$pattern = "/(<script[^>]*>)(".preg_quote($match[1],'/').")(<\/script>)/is";
				$compress = self::compressJS($match[1]);
				$buffer = preg_replace($pattern, '$1'.$compress.'$3', $buffer);
			}
			$pattern = "/<style[^>]*>(.*?)<\/style>/is";
			preg_match_all($pattern, $buffer, $matches, PREG_SET_ORDER, 0);
			foreach ($matches as $match){
				$pattern = "/(<style[^>]*>)(".preg_quote($match[1],'/').")(<\/style>)/is";
				$compress = self::compressCSS($match[1]);
				$buffer = preg_replace($pattern, '$1'.$compress.'$3', $buffer);
			}
			$buffer = preg_replace(array('/<!--[^\[](.*)[^\]]-->/Uis', "/[[:blank:]]+/", '/\s+/'), array('', ' ', ' '), str_replace(array("\n", "\r", "\t"), '', $buffer));
		}
		return $buffer;
	}

	/**
	 * Compress CSS
	 * @param $buffer
	 * @return string
	 */
	public static function compressCSS($buffer) {
		ini_set("zlib.output_compression", "On");
		ini_set("zlib.output_compression_level", "9");
		return preg_replace(array('#\/\*[\s\S]*?\*\/#', '/\s+/'), array('', ' '), str_replace(array("\n", "\r", "\t"), '', $buffer));
	}

	/**
	 * Compress Javascript
	 * @param $buffer
	 * @return string
	 */
	public static function compressJS($buffer) {
		ini_set("zlib.output_compression", "On");
		ini_set("zlib.output_compression_level", "9");
		return str_replace(array("\n", "\r", "\t"), '', preg_replace(array('#\/\*[\s\S]*?\*\/|([^:]|^)\/\/.*$#m', '/\s+/'), array('', ' '), $buffer));
	}

	/**
	 * Check if string is HTML
	 * @param $string
	 * @return bool
	 */
	public static function isHTML($string) {
		//return self::secureStripTagsContent($string) == '' ? true : false;
		return preg_match('/<html.*>/', $string) ? true : false;
	}

	/**
	 * Repair security issue on template
	 * @param $buffer
	 * @return string
	 */
	public static function secureHTML($buffer) {

		$buffer = preg_replace("/<script(?!.*(src\\=))[^>]*>/", "<script type=\"text/javascript\">", $buffer);

		$doc = new DOMDocument();
		$doc->formatOutput = true;
		$doc->preserveWhiteSpace = false;
		$doc->loadHTML($buffer);

		$days_to_cache = self::$headers_cache_days * (60 * 60 * 24);
		$ts = gmdate("D, d M Y H:i:s", time() + $days_to_cache) . " GMT";
		$tags = $doc->getElementsByTagName('head');
		foreach ($tags as $tag) {

			$item = $doc->createElement("meta");
			$item->setAttribute("http-equiv", "cache-control");
			$item->setAttribute("content", "max-age=$days_to_cache, must-revalidate");
			$tag->appendChild($item);

			$item = $doc->createElement("meta");
			$item->setAttribute("http-equiv", "expires");
			$item->setAttribute("content", $ts);
			$tag->appendChild($item);

			$item = $doc->createElement("meta");
			$item->setAttribute("http-equiv", "pragma");
			$item->setAttribute("content", "cache");
			$tag->appendChild($item);
		}

		$tags = $doc->getElementsByTagName('input');
		foreach ($tags as $tag) {
			$type = array("text", "search", "password", "datetime", "date", "month", "week", "time", "datetime-local", "number", "range", "email", "color");
			if (in_array($tag->getAttribute('type'), $type)) {
				$tag->setAttribute("autocomplete", "off");
			}
		}

		$tags = $doc->getElementsByTagName('form');
		foreach ($tags as $tag) {
			$tag->setAttribute("autocomplete", "off");
			// CSRF
			$token = $_SESSION[self::$csrf_session];
			$item = $doc->createElement("input");
			$item->setAttribute("name", self::$csrf_formtoken);
			$item->setAttribute("type", "hidden");
			$item->setAttribute("value", self::escapeSQL($token));
			$tag->appendChild($item);
		}

		$tags = $doc->getElementsByTagName('a');
		foreach ($tags as $tag) {
			$tag->setAttribute("rel", "noopener noreferrer");
		}
		$output = $doc->saveHTML();
		return $output;
	}

	/**
	 * Clean all input globals received
	 * BE CAREFUL THIS METHOD COULD COMPROMISE HTML DATA IF SENT WITH INLINE JS
	 * (send with htmlentities could be a solution if you want send inline js and clean globals at the same time)
	 */
	public static function cleanGlobals() {
		self::saveUnsafeGlobals();
		$_SERVER = self::clean($_SERVER, false, false);
		$_COOKIE = self::clean($_COOKIE, false);
		$_GET = self::clean($_GET, false, false);
		$_POST = self::clean($_POST, true, true, true, self::$clean_post_xss);
		$_REQUEST = array_merge($_GET, $_POST);
	}

	private static $savedGlobals = false;

	/**
	 * Save uncleaned globals
	 */
	private static function saveUnsafeGlobals() {
		if (!self::$savedGlobals) {
			$GLOBALS['UNSAFE_SERVER'] = $_SERVER;
			$GLOBALS['UNSAFE_COOKIE'] = $_COOKIE;
			$GLOBALS['UNSAFE_GET'] = $_GET;
			$GLOBALS['UNSAFE_POST'] = $_POST;
			$GLOBALS['UNSAFE_REQUEST'] = $_REQUEST;
			self::$savedGlobals = true;
		}
	}

	/**
	 * Restore unsafe globals
	 */
	public static function restoreGlobals() {
		$_SERVER = $GLOBALS['UNSAFE_SERVER'];
		$_COOKIE = $GLOBALS['UNSAFE_COOKIE'];
		$_GET = $GLOBALS['UNSAFE_GET'];
		$_POST = $GLOBALS['UNSAFE_POST'];
		$_REQUEST = $GLOBALS['UNSAFE_REQUEST'];
	}

	/**
	 * Useful to compare unsafe globals with safe globals
	 * @return array
	 */
	public static function debugGlobals() {
		$compare = array();
		// SERVER
		$compare['SERVER']['current'] = $_SERVER;
		$compare['SERVER']['unsafe'] = $GLOBALS['UNSAFE_SERVER'];
		$compare['SERVER']['safe'] = self::clean($GLOBALS['UNSAFE_SERVER']);
		// COOKIE
		$compare['COOKIE']['current'] = $_COOKIE;
		$compare['COOKIE']['unsafe'] = $GLOBALS['UNSAFE_COOKIE'];
		$compare['COOKIE']['safe'] = self::clean($GLOBALS['UNSAFE_COOKIE']);
		// GET
		$compare['GET']['current'] = $_GET;
		$compare['GET']['unsafe'] = $GLOBALS['UNSAFE_GET'];
		$compare['GET']['safe'] = self::clean($GLOBALS['UNSAFE_GET']);
		// POST
		$compare['POST']['current'] = $_POST;
		$compare['POST']['unsafe'] = $GLOBALS['UNSAFE_POST'];
		$compare['POST']['safe'] = self::clean($GLOBALS['UNSAFE_POST']);
		// REQUEST
		$compare['REQUEST']['current'] = $_REQUEST;
		$compare['REQUEST']['unsafe'] = $GLOBALS['UNSAFE_REQUEST'];
		$compare['REQUEST']['safe'] = self::clean($GLOBALS['UNSAFE_REQUEST']);
		return $compare;
	}

	/**
	 *  Clean variables (recursive)
	 * @param $data
	 * @param bool $html
	 * @param bool $quotes
	 * @param bool $escape
	 * @param bool $xss
	 * @return array|mixed
	 */
	public static function clean($data, $html = true, $quotes = true, $escape = true, $xss = true) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::clean($v, $html, $quotes, $escape, $xss);
			}
		} else {
			if (!$quotes) $data = str_replace(array('\'', '"'), '', $data);
			if (!$html) $data = self::stripTagsContent($data);
			if ($xss) $data = self::escapeXSS($data);
			if ($escape && self::$escape_string) {
				//$data = self::stripslashes($data);
				$data = self::escapeSQL($data);
			}
		}
		return $data;
	}

	/**
	 * String escape
	 * @param $data
	 * @return mixed
	 */
	public static function escapeSQL($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::escapeSQL($v);
			}
		} else {
			if (!empty($data) && is_string($data)) {
				$search = array("\\", "\x00", "\n", "\r", "'", '"', "\x1a");
				$replace = array("\\\\", "\\0", "\\n", "\\r", "\'", '\"', "\\Z");
				$data = str_replace($search, $replace, $data);
			}
		}
		return $data;
	}

	/**
	 * Attribute escape
	 * @param $data
	 * @return mixed
	 */
	public static function escapeAttr($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::escapeAttr($v);
			}
		} else {
			if (!empty($data) && is_string($data)) {
				$data = htmlentities($data, ENT_QUOTES, ini_get('default_charset'), false);
			}
		}
		return $data;
	}

	/**
	 * Strip tags recursive
	 * @param $data
	 * @return mixed
	 */
	public static function stripTags($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::stripTags($v);
			}
		} else {
			$data = trim(strip_tags($data));
		}
		return $data;
	}

	/**
	 * Trim recursive
	 * @param $data
	 * @return mixed
	 */
	public static function trim($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::trim($v);
			}
		} else {
			$data = trim($data);
		}
		return $data;
	}

	/**
	 * Strip tags and contents recursive
	 * @param $data
	 * @param string $tags
	 * @param bool $invert
	 * @return array|string
	 */
	public static function stripTagsContent($data, $tags = '', $invert = false) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::stripTagsContent($v, $tags, $invert);
			}
		} else {
			preg_match_all('/<(.+?)[\s]*\/?[\s]*>/si', trim($tags), $tags);
			$tags = array_unique($tags[1]);
			if (is_array($tags) AND count($tags) > 0) {
				if ($invert == false) {
					$data = preg_replace('@<(?!(?:' . implode('|', $tags) . ')\b)(\w+)\b.*?>.*?</\1>@si', '', $data);
				} else {
					$data = preg_replace('@<(' . implode('|', $tags) . ')\b.*?>.*?</\1>@si', '', $data);
				}
			} elseif ($invert == false) {
				$data = preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $data);
			}
		}
		return self::stripTags($data);
	}

	/**
	 * XSS escape
	 * @param $data
	 * @return mixed
	 */
	public static function escapeXSS($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::escapeXSS($v);
			}
		} else {
			$data = str_replace(array("&amp;", "&lt;", "&gt;"), array("&amp;amp;", "&amp;lt;", "&amp;gt;"), $data);
			$data = preg_replace("/(&#*\w+)[- ]+;/u", "$1;", $data);
			$data = preg_replace("/(&#x*[0-9A-F]+);*/iu", "$1;", $data);
			$data = html_entity_decode($data, ENT_COMPAT, "UTF-8");
			$data = preg_replace('#(<[^>]+?[- "\'])(?:on|xmlns)[^>]*+>#iu', '$1>', $data);
			$data = preg_replace('#([a-z]*)[- ]*=[- ]*([`\'"]*)[- ]*j[- ]*a[- ]*v[- ]*a[- ]*s[- ]*c[- ]*r[- ]*i[- ]*p[- ]*t[- ]*:#iu', '$1=$2nojavascript', $data);
			$data = preg_replace('#([a-z]*)[- ]*=([\'"]*)[- ]*v[- ]*b[- ]*s[- ]*c[- ]*r[- ]*i[- ]*p[- ]*t[- ]*:#iu', '$1=$2novbscript', $data);
			$data = preg_replace('#([a-z]*)[- ]*=([\'"]*)[- ]*-moz-binding[- ]*:#u', '$1=$2nomozbinding', $data);
			$data = preg_replace('#(<[^>]+?)style[- ]*=[- ]*[`\'"]*.*?expression[- ]*\([^>]*+>#i', '$1>', $data);
			$data = preg_replace('#(<[^>]+?)style[- ]*=[- ]*[`\'"]*.*?behaviour[- ]*\([^>]*+>#i', '$1>', $data);
			$data = preg_replace('#(<[^>]+?)style[- ]*=[- ]*[`\'"]*.*?s[- ]*c[- ]*r[- ]*i[- ]*p[- ]*t[- ]*:*[^>]*+>#iu', '$1>', $data);
			$data = preg_replace('#</*\w+:\w[^>]*+>#i', '', $data);
			do {
				$old_data = $data;
				$data = preg_replace("#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml|eval|svg|video|math|keygen)[^>]*+>#i", "", $data);
			} while ($old_data !== $data);
			$data = str_replace(chr(0), '', $data);
			$data = preg_replace('%&\s*\{[^}]*(\}\s*;?|$)%', '', $data);
			//$data = str_replace('&', '&amp;', $data);
			$data = preg_replace('/&amp;#([0-9]+;)/', '&#\1', $data);
			$data = preg_replace('/&amp;#[Xx]0*((?:[0-9A-Fa-f]{2})+;)/', '&#x\1', $data);
			$data = preg_replace('/&amp;([A-Za-z][A-Za-z0-9]*;)/', '&\1', $data);
		}
		return $data;
	}

	/**
	 * Stripslashes recursive
	 * @param $data
	 * @return mixed
	 */
	public static function stripslashes($data) {
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				$data[$k] = self::stripslashes($v);
			}
		} else {
			if (get_magic_quotes_gpc()) $data = stripslashes($data);
		}
		return $data;
	}

	/**
	 * CSRF token compare only on POST REQUEST
	 */
	public static function secureCSRF() {
		if ($_SERVER["REQUEST_METHOD"] == "POST") {
			if (!self::secureCSRFCompare())
				$_POST = array();
		}
		if (!isset($_SESSION[self::$csrf_session])) {
			self::secureCSRFGenerate();
		}
	}

	/**
	 * CSRF token compare
	 * @return bool
	 */
	private static function secureCSRFCompare() {
		$referer = $_SERVER["HTTP_REFERER"];
		if (!isset($referer)) return false;
		if (strpos($_SERVER["SERVER_NAME"], $referer) != 0) return false;
		$token = $_SESSION[self::$csrf_session];
		if ($_POST[self::$csrf_formtoken] == $token) {
			self::secureCSRFGenerate();
			return true;
		}
		return false;
	}

	/**
	 * Generate CSRF Token
	 */
	private static function secureCSRFGenerate() {
		$guid = self::generateGUID();
		$_SESSION[self::$csrf_session] = md5($guid . time() . ":" . session_id());
	}

	/**
	 * Get CSRF Token
	 * @return mixed
	 */
	public static function secureCSRFToken() {
		$token = $_SESSION[self::$csrf_session];
		return $token;
	}

	/**
	 * Check if clients use Tor
	 * @return bool
	 */
	public static function clientIsTor() {
		$ip = self::clientIP();
		$ip_server = gethostbyname($_SERVER['SERVER_NAME']);

		$query = array(
			implode('.', array_reverse(explode('.', $ip))),
			$_SERVER["SERVER_PORT"],
			implode('.', array_reverse(explode('.', $ip_server))),
			'ip-port.exitlist.torproject.org'
		);

		$torExitNode = implode('.', $query);

		$dns = dns_get_record($torExitNode, DNS_A);

		if (array_key_exists(0, $dns) && array_key_exists('ip', $dns[0])) {
			if ($dns[0]['ip'] == '127.0.0.2') return true;
		}

		return false;
	}

	/**
	 * Block Tor clients
	 */
	public static function secureBlockTor() {
		if (self::clientIsTor())
			self::error(403, 'Permission denied!');
	}

	/**
	 * Get Real IP Address
	 * @return string
	 */
	public static function clientIP() {
		foreach (
			array(
				'HTTP_CF_CONNECTING_IP',
				'HTTP_CLIENT_IP',
				'HTTP_X_FORWARDED_FOR',
				'HTTP_X_FORWARDED',
				'HTTP_X_CLUSTER_CLIENT_IP',
				'HTTP_FORWARDED_FOR',
				'HTTP_FORWARDED',
				'REMOTE_ADDR'
			) as $key) {
			if (array_key_exists($key, $_SERVER) === true) {
				foreach (explode(',', $_SERVER[$key]) as $ip) {
					if ($ip == "::1") return "127.0.0.1";
					return $ip;
				}
			}
		}
		return "0.0.0.0";
	}

	/**
	 * Prevent bad bots
	 */
	public static function secureBlockBots() {
		// Block bots
		if (preg_match("/(spider|crawler|slurp|teoma|archive|track|snoopy|lwp|client|libwww)/i", $_SERVER['HTTP_USER_AGENT']) ||
			preg_match("/(havij|libwww-perl|wget|python|nikto|curl|scan|java|winhttp|clshttp|loader)/i", $_SERVER['HTTP_USER_AGENT']) ||
			preg_match("/(%0A|%0D|%27|%3C|%3E|%00)/i", $_SERVER['HTTP_USER_AGENT']) ||
			preg_match("/(;|<|>|'|\"|\)|\(|%0A|%0D|%22|%27|%28|%3C|%3E|%00).*(libwww-perl|wget|python|nikto|curl|scan|java|winhttp|HTTrack|clshttp|archiver|loader|email|harvest|extract|grab|miner)/i", $_SERVER['HTTP_USER_AGENT']))
			self::error(403, 'Permission denied!');
		// Block Fake google bot
		self::blockFakeGoogleBots();
	}

	/**
	 * Prevent Fake Google Bots
	 */
	private static function blockFakeGoogleBots() {
		$user_agent = (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
		if (preg_match('/googlebot/i', $user_agent, $matches)) {
			$ip = self::clientIP();
			$name = gethostbyaddr($ip);
			$host_ip = gethostbyname($name);
			if (preg_match('/googlebot/i', $name, $matches)) {
				if ($host_ip != $ip)
					self::error(403, 'Permission denied!');
			} else self::error(403, 'Permission denied!');
		}
	}

	/**
	 * Generate captcha image
	 * @param bool $base64
	 * @return string
	 */
	public static function captcha($base64 = false) {

		$md5_hash = md5(rand(0, 9999));
		$security_code = substr($md5_hash, rand(0, 15), 5);

		$spook = ': : : : : : : : : : :';

		$_SESSION["CAPTCHA_CODE"] = $security_code;

		$width = 100;
		$height = 25;

		$image = imagecreate($width, $height);

		$background_color = imagecolorallocate($image, 0, 0, 0);
		$text_color = imagecolorallocate($image, 233, 233, 233);
		$strange1_color = imagecolorallocate($image, rand(100, 255), rand(100, 255), rand(100, 255));
		$strange2_color = imagecolorallocate($image, rand(100, 255), rand(100, 255), rand(100, 255));
		$shape1_color = imagecolorallocate($image, rand(100, 255), rand(100, 255), rand(100, 255));
		$shape2_color = imagecolorallocate($image, rand(100, 255), rand(100, 255), rand(100, 255));

		imagefill($image, 0, 0, $background_color);

		imagestring($image, 5, 30, 4, $security_code, $text_color);

		imagestring($image, 0, rand(0, $width / 2), rand(0, $height), $spook, $strange1_color);
		imagestring($image, 0, rand(0, $width / 2), rand(0, $height), $spook, $strange2_color);
		imageellipse($image, 0, 0, rand($width / 2, $width * 2), rand($height, $height * 2), $shape1_color);
		imageellipse($image, 0, 0, rand($width / 2, $width * 2), rand($height, $height * 2), $shape2_color);

		if($base64) {
			$path = tempnam(sys_get_temp_dir(), 'captcha_');
			imagepng($image, $path);
			$png = base64_encode(file_get_contents($path));
			unlink($path);
			imagedestroy($image);
			return $png;
		} else {
			header("Content-Type: image/png");
			ob_clean();
			imagepng($image);
			imagedestroy($image);
			die();
		}
	}

	/**
	 * Return the captcha input code
	 * @param string $class
	 * @return string
	 */
	public static function captchaPrint($class = '', $input_name = 'captcha'){
		$img = self::captcha(true);
		$captcha = '<img class="'.$class.'" src="data:image/png;base64,'.$img.'" alt="Captcha" />';
		$captcha .= '<input type="text" class="'.$class.'" name="'.$input_name.'">';
		return $captcha;
	}

	/**
	 * Return captcha
	 * @return mixed
	 */
	public static function captchaCode(){
		return $_SESSION["CAPTCHA_CODE"];
	}

	/**
	 * Validate captcha
	 * @param $input_name
	 * @return bool
	 */
	public static function captchaVerify($input_name = 'captcha') {
		if ($_SERVER["REQUEST_METHOD"] == "POST") {
			if (strtolower($_POST[$input_name]) == strtolower($_SESSION["CAPTCHA_CODE"]) && !empty($_SESSION["CAPTCHA_CODE"]))
				return true;
			return false;
		}
		return true;
	}

	/**
	 * Hijacking prevention
	 */
	public static function secureHijacking() {
		if (!empty($_SESSION['HTTP_USER_TOKEN']) && $_SESSION['HTTP_USER_TOKEN'] != md5($_SERVER['HTTP_USER_AGENT'] . ':' . self::clientIP() . ':' . self::$_SALT)) {
			session_unset();
			session_destroy();
			self::error(403, 'Permission denied!');
		}

		$_SESSION['HTTP_USER_TOKEN'] = md5($_SERVER['HTTP_USER_AGENT'] . ':' . self::clientIP() . ':' . self::$_SALT);
	}

	/**
	 * Secure Upload
	 * @param $file
	 * @param $path
	 * @return bool
	 */
	public static function secureUpload($file, $path) {
		if (!file_exists($path)) return false;
		if (!is_uploaded_file($_FILES[$file]["tmp_name"])) return false;
		if (!self::secureScanFile($_FILES[$file]["tmp_name"])) return false;
		if (move_uploaded_file($_FILES[$file]["tmp_name"], $path)) {
			return true;
		}
		return false;
	}

	/**
	 * Secure download
	 * @param $filename
	 */
	public static function secureDownload($filename) {
		ob_clean();
		header('Content-Type: application/x-octet-stream');
		header('Content-Transfer-Encoding: binary');
		header('Content-Disposition: attachment; filename="' . $filename . '";');
		die(file_get_contents($filename));
	}

	/**
	 * Crypt
	 * @param $string
	 * @param $key
	 * @param $action
	 * @return bool|string
	 */
	public static function crypt($string, $key = null, $action = 'encrypt') {

		if (!function_exists('crypt') || !function_exists('hash') || !function_exists('openssl_encrypt'))
			return false;

		$encrypt_method = "AES-256-CBC";

		if (empty($key) && empty($_SESSION['HTTP_USER_KEY']))
			$_SESSION['HTTP_USER_KEY'] = md5(self::generateGUID());

		$secret_key = (empty($key) ? $_SESSION['HTTP_USER_KEY'] : $key) . ':KEY'.self::$_SALT;
		$secret_iv = (empty($key) ? $_SESSION['HTTP_USER_KEY'] : $key) . ':IV'.self::$_SALT;

		$key = hash('sha512', $secret_key);
		$iv = substr(hash('sha512', $secret_iv), 0, 16);
		switch ($action){
			case 'decrypt':
				$output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
				break;
			case 'encrypt':
			default:
				$output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
				$output = base64_encode($output);
				break;
		}
		return $output;
	}

	/**
	 * Decrypt
	 * @param $string
	 * @param $key
	 * @return bool|string
	 */
	public static function decrypt($string, $key = null) {
		return self::crypt($string,'decrypt', $key);
	}

	/**
	 * Set Cookie
	 * @param $name
	 * @param $value
	 * @param int $expires
	 * @param string $path
	 * @param null $domain
	 * @param bool $secure
	 * @param bool $httponly
	 * @return bool
	 */
	public static function setCookie($name, $value, $expires = 2592000, $path = "/", $domain = null, $secure = false, $httponly = false) {
		if ($name != session_name()) {
			$newValue = self::crypt($value);
			if (!setcookie($name, $newValue, $expires, $path, $domain, $secure, $httponly)) return false;
			$_COOKIE[$name] = $value;
			return true;
		}
		return false;
	}

	/**
	 * Unset Cookie
	 * @param $name
	 * @return null
	 */
	public static function unsetCookie($name) {
		if (isset($_COOKIE[$name])) {
			unset($_COOKIE[$name]);
			setcookie($name, null, -1, '/');
		}
		return null;
	}

	/**
	 * Get Cookie
	 * @param $name
	 * @return null
	 */
	public static function getCookie($name) {
		if (isset($_COOKIE[$name])) {
			$cookie = self::decrypt($_COOKIE[$name]);
			return $cookie;
		}
		return null;
	}

	/**
	 * Custom Error
	 * @param integer $code
	 * @param string $message
	 * @param string $title
	 * @return bool
	 */
	public static function error($code = 404, $message = "Not found!", $title = 'Error') {
		if(empty(self::$error_callback)) {
			ob_clean();
			http_response_code($code);
			$output = str_replace('${ERROR_TITLE}', $title, self::$error_template);
			$output = str_replace('${ERROR_BODY}', $message, $output);
			die($output);
		}
		call_user_func(self::$error_callback, $code);
		return true;
	}

	public static $SCAN_DEF = array("functions" =>
		array(
			"il_exec",
			"shell_exec",/*
				"eval",
				"exec",
				"create_function",
				"assert",
				"system",*/
			"syslog",
			"passthru",/*
				"dl",
				"define_syslog_variables",
				"debugger_off",
				"debugger_on",
				"stream_select",
				"parse_ini_file",*/
			"show_source",/*
				"symlink",
				"popen",*
				"posix_getpwuid",*/
			"posix_kill",/*
				"posix_mkfifo",
				"posix_setpgid",
				"posix_setsid",
				"posix_setuid",
				"posix_uname",*/
			"proc_close",/*
				"proc_get_status",
				"proc_nice",*/
			"proc_open",
			"proc_terminate",/*
				"ini_alter",
				"ini_get_all",
				"ini_restore",
				"parse_ini_file",*/
			"inject_code",
			"apache_child_terminate",/*
				"apache_setenv",
				"apache_note",
				"define_syslog_variables",
				"escapeshellarg",
				"escapeshellcmd",
				"ob_start",*/
		),
		"exploits" => array(
			"eval_chr" => "/chr\s*\(\s*101\s*\)\s*\.\s*chr\s*\(\s*118\s*\)\s*\.\s*chr\s*\(\s*97\s*\)\s*\.\s*chr\s*\(\s*108\s*\)/i",
			//"eval_preg" => "/(preg_replace(_callback)?|mb_ereg_replace|preg_filter)\s*\(.+(\/|\\x2f)(e|\\x65)['\"]/i",
			"align" => "/(\\\$\w+=[^;]*)*;\\\$\w+=@?\\\$\w+\(/i",
			"b374k" => "/'ev'\.'al'\.'\(\"\?>/i",  // b374k shell
			"weevely3" => "/\\\$\w=\\\$[a-zA-Z]\('',\\\$\w\);\\\$\w\(\);/i",  // weevely3 launcher
			"c99_launcher" => "/;\\\$\w+\(\\\$\w+(,\s?\\\$\w+)+\);/i",  // http://bartblaze.blogspot.fr/2015/03/c99shell-not-dead.html
			//"too_many_chr" => "/(chr\([\d]+\)\.){8}/i",  // concatenation of more than eight `chr()`
			//"concat" => "/(\\\$[^\n\r]+\.){5}/i",  // concatenation of more than 5 words
			//"concat_with_spaces" => "/(\\\$[^\\n\\r]+\. ){5}/i",  // concatenation of more than 5 words, with spaces
			//"var_as_func" => "/\\\$_(GET|POST|COOKIE|REQUEST|SERVER)\s*\[[^\]]+\]\s*\(/i",
			"escaped_path" => "/(\\x[0-9abcdef]{2}[a-z0-9.-\/]{1,4}){4,}/i",
			//"infected_comment" => "/\/\*[a-z0-9]{5}\*\//i", // usually used to detect if a file is infected yet
			"hex_char" => "/\\[Xx](5[Ff])/i",
			"download_remote_code" => "/echo\s+file_get_contents\s*\(\s*base64_url_decode\s*\(\s*@*\\\$_(GET|POST|SERVER|COOKIE|REQUEST)/i",
			"globals_concat" => "/\\\$GLOBALS\[\\\$GLOBALS['[a-z0-9]{4,}'\]\[\d+\]\.\\\$GLOBALS\['[a-z-0-9]{4,}'\]\[\d+\]./i",
			"globals_assign" => "/\\\$GLOBALS\['[a-z0-9]{5,}'\] = \\\$[a-z]+\d+\[\d+\]\.\\\$[a-z]+\d+\[\d+\]\.\\\$[a-z]+\d+\[\d+\]\.\\\$[a-z]+\d+\[\d+\]\./i",
			"php_inline_long" => "/^.*<\?php.{1000,}\?>.*$/i",
			"base64_long" => "/['\"][A-Za-z0-9+\/]{260,}={0,3}['\"]/",
			"clever_include" => "/include\s*\(\s*[^\.]+\.(png|jpe?g|gif|bmp)/i",
			"basedir_bypass" => "/curl_init\s*\(\s*[\"']file:\/\//i",
			"basedir_bypass2" => "/file\:file\:\/\//i", // https://www.intelligentexploit.com/view-details.html?id=8719
			"non_printable" => "/(function|return|base64_decode).{,256}[^\\x00-\\x1F\\x7F-\\xFF]{3}/i",
			"double_var" => "/\\\${\s*\\\${/i",
			"double_var2" => "/\${\$[0-9a-zA-z]+}/i",
			"hex_var" => "/\\\$\{\\\"\\\\x/i", // check for ${"\xFF"}, IonCube use this method ${"\x
			"register_function" => "/register_[a-z]+_function\s*\(\s*['\\\"]\s*(eval|assert|passthru|exec|include|system|shell_exec|`)/i",  // https://github.com/nbs-system/php-malware-finder/issues/41
			"safemode_bypass" => "/\\x00\/\.\.\/|LD_PRELOAD/i",
			"ioncube_loader" => "/IonCube\_loader/i"
		)
	);

	/**
	 * File scanner
	 * @param $file
	 * @return bool
	 */
	public static function secureScanFile($file) {

		$contents = file_get_contents($file);

		if (empty($file) || !file_exists($file))
			return false;

		foreach (self::$scanner_whitelist as $value) {
			$value = trim(realpath($value));
			if (!empty($value) && (preg_match('#' . preg_quote($value) . '#i', realpath(dirname($file)))
					|| preg_match('#' . preg_quote($value) . '#i', realpath($file))))
				return true;
		}

		foreach (self::$SCAN_DEF["exploits"] as $pattern) {
			if (@preg_match($pattern, $contents))
				return false;
		}

		$contents = preg_replace("/<\?php(.*?)(?!\B\"[^\"]*)\?>(?![^\"]*\"\B)/si", "$1", $contents); // Only php code
		$contents = preg_replace("/\/\*.*?\*\/|\/\/.*?\n|\#.*?\n/i", "", $contents); // Remove comments
		$contents = preg_replace("/('|\")[\s\r\n]*\.[\s\r\n]*('|\")/i", "", $contents); // Remove "ev"."al"
		if (preg_match("/^text/i", mime_content_type($file))) {
			foreach (self::$SCAN_DEF["functions"] as $pattern) {
				if (@preg_match("/(" . $pattern . ")[\s\r\n]*()/i", $contents))
					return false;
				if (@preg_match("/(" . preg_quote(base64_encode($pattern)) . ")/i", $contents))
					return false;
				$field = bin2hex($pattern);
				$field = chunk_split($field, 2, "\\x");
				$field = "\\x" . substr($field, 0, -2);
				if (@preg_match("/(" . $field . ")/i", $contents))
					return false;
				//return array($pattern,realpath($file));
			}
		}
		return true;
	}

	/**
	 * Directory scanner
	 * @param $path
	 * @return array
	 */
	public static function secureScanPath($path) {
		$potentially_infected = array();
		if (empty($path) || !glob($path))
			return array();
		$files = self::recursiveGlob($path);
		foreach ($files as $file) {
			if (!self::secureScanFile($file))
				$potentially_infected[] = $file;
		}
		return $potentially_infected;
	}

	/**
	 * Scan and rename bad files
	 * @param $path
	 */
	public static function secureScan($path) {
		$files = self::secureScanPath($path);
		foreach ($files as $file) {
			rename($file, $file . ".bad");
		}
	}

	/**
	 * Glob recursive
	 * @param $pattern
	 * @param int $flags
	 * @return array
	 */
	private static function recursiveGlob($pattern, $flags = 0) {
		$files = glob($pattern, $flags);
		foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
			$files = array_merge($files, self::recursiveGlob($dir . '/' . basename($pattern), $flags));
		}
		return $files;
	}

	/**
	 * Check if the request is HTTPS
	 */
	private static function checkHTTPS() {
		if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) {
			return true;
		}
		return false;
	}

	/**
	 * Write on htaccess the DOS Attempts
	 * @param $ip
	 * @param $file_attempts
	 */
	private static function secureDOSWriteAttempts($ip, $file_attempts) {
		$ip_quote = preg_quote($ip);
		$content = @file_get_contents($file_attempts);
		if (preg_match("/### BEGIN: DOS Attempts ###[\S\s.]*# $ip_quote => ([0-9]+):([0-9]+):([0-9]+):([0-9]+)[\S\s.]*### END: DOS Attempts ###/i", $content, $attemps)) {
			$row_replace = "# $ip => " . $_SESSION['DOS_ATTEMPTS_TIMER'] . ":" . $_SESSION['DOS_ATTEMPTS'] . ":" . $_SESSION['DOS_COUNTER'] . ":" . $_SESSION['DOS_TIMER'];
			$content = preg_replace("/(### BEGIN: DOS Attempts ###[\S\s.]*)(# $ip_quote => [0-9]+:[0-9]+:[0-9]+:[0-9]+)([\S\s.]*### END: DOS Attempts ###)/i",
				"$1$row_replace$3", $content);
		} else if (preg_match("/### BEGIN: DOS Attempts ###([\S\s.]*)### END: DOS Attempts ###/i", $content)) {
			$row = "# $ip => " . $_SESSION['DOS_ATTEMPTS_TIMER'] . ":" . $_SESSION['DOS_ATTEMPTS'] . ":" . $_SESSION['DOS_COUNTER'] . ":" . $_SESSION['DOS_TIMER'];
			$content = preg_replace("/(### BEGIN: DOS Attempts ###)([\S\s.]*)([\r\n]+### END: DOS Attempts ###)/i",
				"$1$2$row$3", $content);
		} else {
			$content .= "### BEGIN: DOS Attempts ###";
			$content .= "\r\n# $ip => " . $_SESSION['DOS_ATTEMPTS_TIMER'] . ":" . $_SESSION['DOS_ATTEMPTS'] . ":" . $_SESSION['DOS_COUNTER'] . ":" . $_SESSION['DOS_TIMER'];
			$content .= "\r\n### END: DOS Attempts ###";
		}
		file_put_contents($file_attempts, $content);
	}

	/**
	 * Remove from htaccess the DOS Attempts
	 * @param $ip
	 * @param $file_attempts
	 */
	private static function secureDOSRemoveAttempts($ip, $file_attempts) {
		$ip_quote = preg_quote($ip);
		$content = @file_get_contents($file_attempts);
		if (preg_match("/### BEGIN: DOS Attempts ###[\S\s.]*[\r\n]+# $ip_quote => ([0-9]+):([0-9]+):([0-9]+):([0-9]+)[\S\s.]*### END: DOS Attempts ###/i", $content, $attemps)) {
			$content = preg_replace("/(### BEGIN: DOS Attempts ###[\S\s.]*)([\r\n]+# $ip_quote => [0-9]+:[0-9]+:[0-9]+:[0-9]+)([\S\s.]*### END: DOS Attempts ###)/i", "$1$3", $content);
		}
		file_put_contents($file_attempts, $content);
	}

	/**
	 * Remove from htaccess the DOS Attempts
	 * @param $time_expire
	 * @param $file_attempts
	 */
	private static function secureDOSRemoveOldAttempts($time_expire, $file_attempts) {
		$time = $_SERVER['REQUEST_TIME'];
		$pattern = "/# ((([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])) => ([0-9]+):([0-9]+):([0-9]+):([0-9]+)[\r\n]+/i";
		$content = @file_get_contents($file_attempts);
		if (preg_match_all($pattern, $content, $attemps)) {
			foreach ($attemps as $attemp) {
				preg_match($pattern, $attemp[0], $attemp);
				$ip_quote = preg_quote($attemp[1]);
				if ($time > $attemp[5] + $time_expire || $time > $attemp[8] + $time_expire)
					$content = preg_replace("/(### BEGIN: DOS Attempts ###[\S\s.]*)([\r\n]+# $ip_quote => [0-9]+:[0-9]+:[0-9]+:[0-9]+)([\S\s.]*### END: DOS Attempts ###)/i", "$1$3", $content);
			}
		}
		file_put_contents($file_attempts, $content);
	}

	/**
	 * Read from htaccess the DOS Attempts
	 * @param $ip
	 * @param $content
	 */
	private static function secureDOSReadAttempts($ip, $content) {
		$ip_quote = preg_quote($ip);
		if (preg_match("/### BEGIN: DOS Attempts ###[\S\s.]*[\r\n]+# $ip_quote => ([0-9]+):([0-9]+):([0-9]+):([0-9]+)[\S\s.]*### END: DOS Attempts ###/i", $content, $attemps)) {
			$_SESSION['DOS_ATTEMPTS_TIMER'] = $attemps[1];
			$_SESSION['DOS_ATTEMPTS'] = $attemps[2];
			$_SESSION['DOS_COUNTER'] = $attemps[3];
			$_SESSION['DOS_TIMER'] = $attemps[4];
		}
	}

	/**
	 * Block DOS Attacks
	 */
	public static function secureDOS() {

		$time_safe = 1.5; // Time safe from counter to wait (for css/js requests if not set $isAPI)
		$time_counter = 3; // Time within counter (now + ($time_counter - $time_safe))

		$time_waiting = 10; // Time to wait after reach 10 requests
		$time_expire = 3600; // Time to reset attempts

		$time = $_SERVER['REQUEST_TIME'];
		$ip = self::clientIP();
		$htaccess = realpath(self::$basedir . "/.htaccess");
		$file_attempts = realpath(self::$basedir) . "/.ddos";
		$content = @file_get_contents($file_attempts);
		self::secureDOSRemoveOldAttempts($time_expire, $file_attempts);

		if (!isset($_SESSION['DOS_COUNTER']) || !isset($_SESSION['DOS_ATTEMPTS']) || empty($_SESSION['DOS_ATTEMPTS_TIMER']) || empty($_SESSION['DOS_TIMER'])) {
			self::secureDOSReadAttempts($ip, $file_attempts);
			$_SESSION['DOS_COUNTER'] = 0;
			$_SESSION['DOS_ATTEMPTS'] = 0;
			$_SESSION['DOS_ATTEMPTS_TIMER'] = $time;
			$_SESSION['DOS_TIMER'] = $time;
			self::secureDOSWriteAttempts($ip, $file_attempts);
		} else if ($_SESSION['DOS_TIMER'] != $time) {

			if ($time > $_SESSION['DOS_TIMER'] + $time_expire)
				$_SESSION['DOS_ATTEMPTS'] = 0;

			if ($_SESSION['DOS_COUNTER'] >= 10 && $_SESSION['DOS_ATTEMPTS'] < 2) {
				if ($time > $_SESSION['DOS_TIMER'] + $time_waiting) {
					$_SESSION['DOS_ATTEMPTS'] = $_SESSION['DOS_ATTEMPTS'] + 1;
					$_SESSION['DOS_ATTEMPTS_TIMER'] = $time;
					$_SESSION['DOS_TIMER'] = $time;
					$_SESSION['DOS_COUNTER'] = 0;
				} else {
					$url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
					$seconds = round(($_SESSION['DOS_TIMER'] + $time_waiting) - time());
					if ($seconds < 1) header("Location: {$url}");
					header("Refresh: {$seconds}; url={$url}");

					self::error(403, 'Permission Denied!<br>You must wait ' . $seconds . ' seconds...');
				}
				self::secureDOSWriteAttempts($ip, $file_attempts);
			} else if ($_SESSION['DOS_COUNTER'] >= 10 && $_SESSION['DOS_ATTEMPTS'] > 1) {
				$htaccess_content = file_get_contents($htaccess);
				if (preg_match("/### BEGIN: BANNED IPs ###\n/i", $content)) {
					$htaccess_content = preg_replace("/(### BEGIN: BANNED IPs ###[\r\n]+)([\S\s.]*?)([\r\n]+### END: BANNED IPs ###)/i", "$1$2\r\nDeny from $ip$3", $htaccess_content);
				} else {
					$htaccess_content .= "\r\n\r\n### BEGIN: BANNED IPs ###\r\n";
					$htaccess_content .= "Order Allow,Deny\r\n";
					$htaccess_content .= "Deny from $ip\r\n";
					$htaccess_content .= "### END: BANNED IPs ###";
				}
				file_put_contents($htaccess, $htaccess_content);
				self::secureDOSRemoveAttempts($ip, $file_attempts);
			} else {
				if ($_SESSION['DOS_TIMER'] < ($time - $time_safe)) {
					if ($_SESSION['DOS_TIMER'] > ($time - $time_counter)) {
						$_SESSION['DOS_COUNTER'] = $_SESSION['DOS_COUNTER'] + 1;
					} else {
						$_SESSION['DOS_COUNTER'] = 0;
					}
					$_SESSION['DOS_TIMER'] = $time;
					self::secureDOSWriteAttempts($ip, $file_attempts);
				}
			}
		}
	}

	/**
	 * Generate strong password
	 * @param int $length
	 * @param string $available_sets
	 * @return bool|string
	 */
	public static function generatePassword($length = 8, $available_sets = 'luns') {
		$sets = array();
		// lowercase
		if (strpos($available_sets, 'l') !== false)
			$sets[] = 'abcdefghjkmnpqrstuvwxyz';
		// uppercase
		if (strpos($available_sets, 'u') !== false)
			$sets[] = 'ABCDEFGHJKMNPQRSTUVWXYZ';
		// numbers
		if (strpos($available_sets, 'n') !== false)
			$sets[] = '0123456789';
		// special chars
		if (strpos($available_sets, 's') !== false)
			$sets[] = '_-=+!@#$%&*?/';
		$all = '';
		$password = '';
		foreach ($sets as $set) {
			$password .= $set[array_rand(str_split($set))];
			$all .= $set;
		}
		$all = str_split($all);
		for ($i = 0; $i < $length - count($sets); $i++)
			$password .= $all[array_rand($all)];
		$password = str_shuffle($password);
		return $password;
	}

	/**
	 * Generate user friendly password
	 * @param $string
	 * @param $strong_lv (0-2)
	 * @return mixed|string
	 *
	 * @example generateFriendlyPassword("Marco Cesarato 1996"); // RANDOM OUTPUT: Ce$Ar4t0_m4RCo_1996
	 */
	public static function generateFriendlyPassword($string, $strong_lv = 1) {
		$alpha_replace = array(
			'A' => '4',
			'B' => '8',
			'E' => '3',
			'S' => '$',
			'I' => '1',
			'O' => '0',
			'T' => '7',
			'L' => '2',
			'G' => '6',
		);
		$numeric_replace = array(
			'0' => 'O',
			'1' => '!',
			'4' => 'A',
			'5' => 'S',
			'6' => 'G',
			'7' => 'T',
			'8' => 'B',
		);
		$special = '_=-+#@%&*!?';
		$string = strtolower($string);

		$estr = explode(' ', $string);

		foreach ($estr as &$str) {

			$astr = str_split($str);
			$new_astr = array();

			foreach ($astr as $i => $char) {
				$char = rand(0, 100) > 50 ? strtoupper($char) : $char;
				if ($strong_lv > 0 &&
					(!empty($astr[$i - 1]) && ($new_astr[$i - 1] == $astr[$i - 1] || $astr[$i] == $astr[$i - 1]) ||
						!empty($astr[$i + 1]) && $astr[$i] == $astr[$i + 1])) {
					if($strong_lv > 1) $char = str_replace(array_keys($numeric_replace), $numeric_replace, $char);
					if(strtolower($astr[$i]) == strtolower($char)) $char = str_replace(array_keys($alpha_replace), $alpha_replace, strtoupper($char));
				}
				$new_astr[] = $char;
			}
			$str = implode('', $new_astr);
		}

		shuffle($estr);
		$string = implode(' ', $estr);
		$string = str_replace(' ', $special[rand(0, strlen($special) - 1)], $string);
		return $string;
	}

	/**
	 * Hash password
	 * @param $password
	 * @param $cost (4-30)
	 * @return bool|null|string
	 */
	public static function passwordHash($password, $cost = 10) {
		if (!function_exists('crypt')) return false;

		if (is_null($password) || is_int($password)) {
			$password = (string) $password;
		}
		if ($cost < 4 || $cost > 31) {
			trigger_error(sprintf("Invalid bcrypt cost parameter specified: %d", $cost), E_USER_WARNING);
			return null;
		}
		$required_salt_len = 22;
		$hash_format = sprintf("$2y$%02d$", $cost);
		$resultLength = 60;
		$base64_digits =
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
		$bcrypt64_digits =
			'./ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$base64_string = base64_encode(self::$_SALT);
		$salt = strtr(rtrim($base64_string, '='), $base64_digits, $bcrypt64_digits);
		$salt = substr($salt, 0, $required_salt_len);
		$hash = $hash_format . $salt;
		$ret = crypt($password, $hash);
		if (!is_string($ret) || strlen($ret) != $resultLength) {
			return false;
		}
		return $ret;
	}

	/**
	 * Verify password
	 * @param $password
	 * @param $hash
	 * @return bool
	 */
	public static function passwordVerify($password, $hash) {
		if (!function_exists('crypt')) return false;
		$ret = crypt($password, $hash);
		if (!is_string($ret) || strlen($ret) != strlen($hash) || strlen($ret) <= 13) {
			return false;
		}
		$status = 0;
		for ($i = 0; $i < strlen($ret); $i++) {
			$status |= (ord($ret[$i]) ^ ord($hash[$i]));
		}
		return ($status === 0);
	}

	/**
	 * Create a GUID
	 * @return string
	 */
	public static function generateGUID() {

		$microtime = microtime();
		list($dec, $sec) = explode(' ', $microtime);
		$dec_hex = dechex($dec * 1000000);
		$sec_hex = dechex($sec);
		$dec_hex = (strlen($dec_hex) <= 5) ? str_pad($dec_hex, 5, '0') : substr($dec_hex, 0, 5);
		$sec_hex = (strlen($sec_hex) <= 6) ? str_pad($sec_hex, 6, '0') : substr($sec_hex, 0, 6);

		// Section 1 (length 8)
		$guid = $dec_hex;
		for ($i = 0; $i < 3; ++$i)
			$guid .= dechex(mt_rand(0, 15));
		$guid .= '-';
		// Section 2 (length 4)
		for ($i = 0; $i < 4; ++$i)
			$guid .= dechex(mt_rand(0, 15));
		$guid .= '-';
		// Section 3 (length 4)
		for ($i = 0; $i < 4; ++$i)
			$guid .= dechex(mt_rand(0, 15));
		$guid .= '-';
		// Section 4 (length 4)
		for ($i = 0; $i < 4; ++$i)
			$guid .= dechex(mt_rand(0, 15));
		$guid .= '-';
		// Section 5 (length 12)
		$guid .= $sec_hex;
		for ($i = 0; $i < 6; ++$i)
			$guid .= dechex(mt_rand(0, 15));

		return $guid;
	}
}
