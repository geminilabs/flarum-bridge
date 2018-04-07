<?php

namespace GeminiLabs\FlarumBridge;

use WP_Error;
use WP_User;

class Flarum
{
	const REMEMBER_ME_KEY = 'flarum_remember';

	protected $settings;

	public function __construct( $settings )
	{
		$this->settings = $settings;
	}

	/**
	 * @param WP_User|null $user
	 * @param string $username
	 * @param string $password
	 * @return null|WP_User
	 * @filter authenticate
	 */
	public function loginUser( $user, $username, $password )
	{
		if( $user instanceof WP_User ) {
			$this->login( $user, wp_hash_password( $password ));
		}
		return $user;
	}

	/**
	 * @return void
	 * @action wp_logout
	 */
	public function logoutUser()
	{
		$this->logout();
	}

	/**
	 * @param string $redirect
	 * @param string $requested_redirect
	 * @param WP_User|WP_Error $user
	 * @return string
	 * @filter login_redirect
	 */
	public function redirectUser( $redirect, $requestedRedirect, $user )
	{
		if( $redirect === 'forum' && $user instanceof WP_User ) {
			$this->redirectToFlarum();
		}
		return $redirect;
	}

	/**
	 * @param string $password
	 * @return void
	 * @action after_password_reset
	 */
	public function updateUserPassword( WP_User $user, $password )
	{
		glfb()->db->updatePassword( $user, $password );
	}

	/**
	 * @return int
	 */
	protected function getLifetimeInSeconds()
	{
		$remember = filter_input( INPUT_POST, 'rememberme' );
		$lifetimeInDays = empty( $remember ) ? 2 : 14;
		return (int)apply_filters( 'auth_cookie_expiration',
			$lifetimeInDays * DAY_IN_SECONDS,
			$user->ID,
			$remember
		);
	}

	/**
	 * @param string $username
	 * @param string $password
	 * @return string
	 */
	protected function getToken( $username, $password )
	{
		$data = [
			'identification' => $username,
			'lifetime' => $this->getLifetimeInSeconds(),
			'password' => $password,
		];
		$response = $this->sendPostRequest( '/api/token', $data );
		return isset( $response['token'] )
			? $response['token']
			: '';
	}

	/**
	 * @param string $password
	 * @return void
	 */
	protected function login( WP_User $user, $password )
	{
		$token = $this->getToken( $user->user_login, $password );
		if( empty( $token )) {
			$this->signup( $user->user_login, $password, $user->user_email );
			$token = $this->getToken( $user->user_login, $password );
		}
		$this->setRememberMeCookie( $token, $user );
	}

	/**
	 * @return void
	 */
	protected function logout()
	{
		$this->removeRememberMeCookie();
	}

	/**
	 * @return void
	 */
	protected function redirectToFlarum()
	{
		wp_redirect( $this->settings->flarum_url );
		exit;
	}

	/**
	 * @return void
	 */
	protected function removeRememberMeCookie()
	{
		unset( $_COOKIE[self::REMEMBER_ME_KEY] );
		$this->setCookie( self::REMEMBER_ME_KEY, '', time() - 10 );
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @param int $expire
	 * @return void
	 */
	protected function setCookie( $name, $value, $expire )
	{
		setcookie( $name, $value, $expire, '/', parse_url( home_url(), PHP_URL_HOST ));
	}

	/**
	 * @param string $path
	 * @param array $data
	 * @return array
	 */
	protected function sendPostRequest( $path, $data )
	{
		$data_string = json_encode( $data );
		$ch = curl_init( untrailingslashit( home_url( $this->settings->flarum_url, 'https' )).$path );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data_string );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false ); // for development
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Authorization: Token '.$this->settings->flarum_api_key.'; userId=1',
			'Content-Length: '.strlen( $data_string ),
			'Content-Type: application/json',
		]);
		$result = curl_exec( $ch );
		return json_decode( $result, true );
	}

	/**
	 * @param string $token
	 * @return void
	 */
	protected function setRememberMeCookie( $token, WP_User $user )
	{
		$expiry = filter_input( INPUT_POST, 'rememberme' )
			? time() + $this->getLifetimeInSeconds()// + ( 12 * HOUR_IN_SECONDS ) //login grace period
			: 0;
		$this->setCookie( self::REMEMBER_ME_KEY, $token, $expiry );
	}

	/**
	 * @param string $username
	 * @param string $password
	 * @param string $email
	 * @return bool
	 */
	protected function signup( $username, $password, $email )
	{
		$data = [
			"data" => [
				"type" => "users",
				"attributes" => [
					"username" => $username,
					"password" => $password,
					"email" => $email,
				]
			]
		];
		$response = $this->sendPostRequest( '/api/users', $data );
		return isset( $response['data']['id'] );
	}
}
