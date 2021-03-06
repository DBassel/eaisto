<?php

namespace JustneroRu\EAISTO;

use Exception;

class SoapWrap extends \SoapClient {

	/**
	 * @var array $classmap The defined classes
	 */
	protected static $classmap = [];

	/**
	 * @var string $wsdl The url to wsdl
	 */
	protected static $wsdl = '';
	protected $timeout = 5000;
	protected $connectTimeout = 1000;
	protected $ssl_enabled = true;
	/**
	 * @var Proxy|false
	 */
	protected $proxy = false;

	/**
	 * @param string $cacheDir path to a directory to store cache
	 * @param array $options A array of config values
	 * @param string $wsdl The wsdl file to use
	 *
	 * @throws Exception
	 */
	public function __construct( $cacheDir, array $options = [], $wsdl = null ) {
		if ( isset( $options['ssl_enabled'] ) ) {
			$this->ssl_enabled = $options['ssl_enabled'];
			unset( $options['ssl_enabled'] );
		}
		if ( isset( $options['timeout'] ) ) {
			$this->__setTimeout( $options['timeout'] );
			unset( $options['timeout'] );
		}
		if ( isset( $options['connect_timeout'] ) ) {
			$this->__setConnectTimeout( $options['connect_timeout'] );
			unset( $options['connect_timeout'] );
		}
		if ( isset( $options['proxy'] ) ) {
			$this->__setProxy( $options['proxy'] );
			unset( $options['proxy'] );
		}
		foreach ( static::$classmap as $key => $value ) {
			if ( ! isset( $options['classmap'][ $key ] ) ) {
				$options['classmap'][ $key ] = $value;
			}
		}
		$options = array_merge( [
			'features'           => 1,
			'exceptions'         => 1,
			'cache_wsdl'         => WSDL_CACHE_NONE,
			'connection_timeout' => ceil( $this->connectTimeout / 1000 ),
		], $options );
		if ( $this->proxy !== false ) {
			$options += $this->proxy->optionsSoap();
		}
		if ( ! $wsdl ) {
			$wsdl = static::$wsdl;
		}
		if ( function_exists( 'xdebug_disable' ) ) {
			/** @noinspection PhpComposerExtensionStubsInspection */
			xdebug_disable();
		}
		if ( ! $this->ssl_enabled ) {
			$options['stream_context'] = $options['stream_context'] ?? stream_context_create( [
					'ssl' => [
						'verify_peer'       => false,
						'verify_peer_name'  => false,
						'allow_self_signed' => true,
					],
				] );
		}
		$done = true;
		do {
			unset( $this->__soap_fault );
			try {
				parent::__construct( $this->getCache( $cacheDir, $wsdl, ! $done ), $options );
				$done = true;
			} catch ( \SoapFault $ex ) {
				$done = false;
			}
			if ( isset( $this->__soap_fault ) ) {
				$done = false;
			}
		} while ( ! $done );
		if ( function_exists( 'xdebug_enable' ) ) {
			/** @noinspection PhpComposerExtensionStubsInspection */
			xdebug_enable();
		}
	}

	/**
	 * @param array|string|Proxy $proxy
	 *
	 * @throws Exception
	 */
	public function __setProxy( $proxy ) {
		if ( is_array( $proxy ) ) {
			$proxy = Proxy::loadArray( $proxy );
		} elseif ( is_string( $proxy ) ) {
			$proxy = Proxy::loadFirst( $proxy );
		}
		if ( is_object( $proxy ) && $proxy instanceof Proxy ) {
			$this->proxy = $proxy;

			return;
		}
		throw new Exception( 'Invalid proxy config' );
	}

	/**
	 * @param string $cacheDir path to a directory to store cache
	 * @param string $wsdl The wsdl file to use
	 * @param bool $force Reload file anyway
	 * @param int $tries How many times to try
	 *
	 * @return string path to a cached copy of wsdl
	 * @throws Exception
	 */
	private function getCache( $cacheDir, $wsdl, $force = false, $tries = 1 ) {
		$filename = hash( 'md5', $wsdl ) . '.wsdl';
		$path     = rtrim( $cacheDir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $filename;
		if ( $force || ! file_exists( $path ) || filemtime( $path ) < time() - 3600 ) {
			do {
				$result = file_put_contents( $path, $this->__curl_get( $wsdl ) );
				$tries --;
			} while ( ! $result && $tries > 0 );
			if ( ! $result ) {
				throw new \Exception( 'Can`t obtain wsdl copy' );
			}
		}

		return $path;
	}

	/**
	 * @param $url
	 *
	 * @return mixed
	 * @throws Exception
	 */
	private function __curl_get( $url ) {
		$curl = curl_init();
		if ( $curl === false ) {
			throw new Exception( 'Curl initialisation failed' );
		}

		$options = [
			CURLOPT_URL              => $url,
			CURLOPT_VERBOSE          => false,
			CURLOPT_RETURNTRANSFER   => true,
			CURLOPT_HEADER           => false,
			CURLOPT_NOSIGNAL         => true,
			CURLOPT_FOLLOWLOCATION   => true,
			CURLOPT_CUSTOMREQUEST    => 'GET',
			CURLOPT_SSL_VERIFYPEER   => $this->ssl_enabled,
			CURLOPT_SSL_VERIFYHOST   => $this->ssl_enabled,
			CURLOPT_SSL_VERIFYSTATUS => $this->ssl_enabled,
		];

		if ( $this->timeout > 0 ) {
			if ( defined( 'CURLOPT_TIMEOUT_MS' ) ) {
				$options[ CURLOPT_TIMEOUT_MS ] = $this->timeout;
			} else {
				$options[ CURLOPT_TIMEOUT ] = ceil( $this->timeout / 1000 );
			}
		}
		if ( $this->connectTimeout > 0 ) {
			if ( defined( 'CURLOPT_CONNECTTIMEOUT_MS' ) ) {
				$options[ CURLOPT_CONNECTTIMEOUT_MS ] = $this->connectTimeout;
			} else {
				$options[ CURLOPT_CONNECTTIMEOUT ] = ceil( $this->connectTimeout / 1000 );
			}
		}
		if ( $this->proxy !== false ) {
			$options += $this->proxy->optionsCurl();
		}

		if ( curl_setopt_array( $curl, $options ) === false ) {
			throw new Exception( 'Failed setting CURL options' );
		}

		$response = curl_exec( $curl );

		if ( curl_errno( $curl ) ) {
			throw new Exception( curl_error( $curl ) );
		}

		curl_close( $curl );

		return $response;
	}

	public function __getTimeout() {
		return $this->timeout;
	}

	/**
	 * @param $timeout
	 *
	 * @throws Exception
	 */
	public function __setTimeout( $timeout ) {
		if ( ! is_int( $timeout ) && ! is_null( $timeout ) || $timeout < 0 ) {
			throw new Exception( 'Invalid timeout value' );
		}

		$this->timeout = $timeout;
	}

	public function __getConnectTimeout() {
		return $this->connectTimeout;
	}

	/**
	 * @param $connectTimeout
	 *
	 * @throws Exception
	 */
	public function __setConnectTimeout( $connectTimeout ) {
		if ( ! is_int( $connectTimeout ) && ! is_null( $connectTimeout ) || $connectTimeout < 0 ) {
			throw new Exception( 'Invalid connecttimeout value' );
		}

		$this->connectTimeout = $connectTimeout;
	}

}