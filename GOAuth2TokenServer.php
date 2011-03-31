<?php

	require_once 'GOAuth2.php';

	/**
	 * OAuth2.0-compliant token endpoint.
	 * @package GOAuth2
	 */
	abstract class GOAuth2TokenServer {

		// The type of token the token server hands out.
		protected $token_type;

		// The method the token server uses to authenticate clients.
		protected $client_auth_method;

		// An array of URIs, indexed by error type, that may be provided to the client.
		protected $error_uris 		= array();

		public function __construct($token_type = GoAuth2::TOKEN_TYPE_MAC, $client_auth_method = GoAuth2::SERVER_AUTH_TYPE_CREDENTIALS) {
			$this->token_type			= $token_type;
			$this->client_auth_method 	= $client_auth_method;
		}

		/**
		 * Handle a request for a token to the token endpoint.
		 *
		 * @param array		$post					The POST fields of the request.
		 * @param String 	$authorization_header	The Authorization header field.
		 */
		public function handleTokenRequest($post, $authorization_header) {

			// Check for required parameters.
			if(!isset($post['grant_type'])) {
				$this->sendErrorResponse(GOAuth2::ERROR_INVALID_REQUEST);
			}

			// Handle the token request depending on its type.
			switch($post['grant_type']) {
				case GOAuth2::GRANT_TYPE_CLIENT_CREDENTIALS:
					$this->handleTokenRequestWithClientCredentials($post, $authorization_header);
					break;
				case GOAuth2::GRANT_TYPE_PASSWORD:
					$this->handleTokenRequestWithPassword($post, $authorization_header);
					break;
				case GoAuth2::GRANT_TYPE_REFRESH_TOKEN:
					$this->handleTokenRefreshRequest($post, $authorization_header);
					break;
				default:
					$this->sendErrorResponse(GOAuth2::ERROR_INVALID_REQUEST);
			}

		}


		private function handleTokenRequestWithPassword($post, $authorization_header) {

			// Get the client_id, client_secret, username, password and scope parameters from the POST if present.
			$client_id 		= isset($post['client_id']) ? $post['client_id'] : null;
			$client_secret 	= isset($post['client_secret']) ? $post['client_secret'] : null;
			$username 		= isset($post['username']) ? $post['username'] : null;
			$password 		= isset($post['password']) ? $post['password'] : null;
			$scope			= isset($post['scope']) ? $post['scope'] : null;

			// Authenticate the client request
			$this->authenticateClientRequest($client_id, $client_secret, $scope, $authorization_header);

			// Check that a username and password was passed
			if(empty($username) || empty($password)) {
				$this->sendErrorResponse(GoAuth2::ERROR_INVALID_REQUEST);
			}

			// Validate the resource owner credentials
			$this->validateResourceOwnerCredentials($username, $password);

			// Get a new token
			$token = $this->generateAccessToken($client_id, $username, $scope);

			$this->sendResponse(GOAuth2::HTTP_200, $token->toJSON(), GOAuth2::CONTENT_TYPE_JSON, $no_store = true);
		}


		/**
		 * Handle a request to refresh an access token with the given refresh token.
		 *
		 * @param array 	$post					The POST array of the request.
		 * @param String	$authorization_header	The contents of the Authorization header.
		 */
		private function handleTokenRefreshRequest($post, $authorization_header) {

			// Get the client_id, client_secret, refresh token and scope parameters from the POST if present.
			$client_id 		= isset($post['client_id']) ? $post['client_id'] : null;
			$client_secret 	= isset($post['client_secret']) ? $post['client_secret'] : null;
			$refresh_token 	= isset($post['refresh_token']) ? $post['refresh_token'] : null;
			$scope			= isset($post['scope']) ? $post['scope'] : null;

			// Authenticate the client request
			$this->authenticateClientRequest($client_id, $client_secret, $scope, $authorization_header);

			// Check that a refresh token was passed
			if(empty($refresh_token)) {
				$this->sendErrorResponse(GoAuth2::ERROR_INVALID_REQUEST);
			}

			// Refresh the access token
			$token = $this->refreshAccessToken($client_id, $refresh_token, $scope);

			// Send the generated token back to the client
			$this->sendResponse(GOAuth2::HTTP_200, $token->toJSON(), GOAuth2::CONTENT_TYPE_JSON, $no_store = true);
		}


		/**
		 * Handle a request for an access token using just the client credentials.
		 * This flow is used when the client would like to obtain access on behalf
		 * of itself.
		 *
		 * @param array		$post					The POST array given with the request.
		 * @param String	$authorization_header	The contents of the Authorization: header.
		 */
		private function handleTokenRequestWithClientCredentials($post, $authorization_header) {

			// Get the client_id, client_secret and scope parameters from the POST if present.
			$client_id 		= isset($post['client_id']) ? $post['client_id'] : null;
			$client_secret 	= isset($post['client_secret']) ? $post['client_secret'] : null;
			$scope			= isset($post['scope']) ? $post['scope'] : null;

			// Authenticate the client request
			$this->authenticateClientRequest($client_id, $client_secret, $scope, $authorization_header);

			// Get a new access token
			$token = $this->generateAccessToken($client_id, $for_user = null, $scope);

			// Send the generated token back to the client
			$this->sendResponse(GOAuth2::HTTP_200, $token->toJSON(), GOAuth2::CONTENT_TYPE_JSON, $no_store = true);
		}


		/**
		 * Authenticate a request from a client using the authentication method
		 * specified by the server.  This will most often be using the method
		 * specified in s3.1 of the OAuth specification, namely the presence of
		 * a client_id and client_secret POST field.
		 *
		 * However, as noted in the specification, other authentication methods
		 * (such as HTTP BASIC) or anonymous access may be permitted.
		 *
		 * @param String 	$client_id
		 * @param String 	$client_secret
		 * @param array		$scope
		 * @param String	$authorization_header
		 */
		private function authenticateClientRequest($client_id, $client_secret, $scope, $authorization_header) {

			switch($this->client_auth_method) {
				case GOAuth2::SERVER_AUTH_TYPE_ANONYMOUS:
					// Anonymous access is permitted.
					return;

				case GOAuth2::SERVER_AUTH_TYPE_HTTP_BASIC:
					// @todo: Implement BASIC authentication support.
					$this->sendErrorResponse(GOAuth2::ERROR_INVALID_CLIENT);
					return;

				case GOAuth2::SERVER_AUTH_TYPE_CREDENTIALS:
					// Using the (default) credentials method.

					// Check for client_id and client_secret, required for request.
					if(empty($client_id) || empty($client_secret)) {
						$this->sendErrorResponse(GOAuth2::ERROR_INVALID_CLIENT);
					}

					// Authenticate the client id and client secret
					$this->authenticateClientCredentials($client_id, $client_secret, $scope);

					// Authentication was successful.
					return;

				default:
					// Unknown authentication method.
					throw new Exception('Unknown server authentication method specified.');
					return;
			}
		}


		/**
		 * Send an error response from the server as specified by the OAuth 2.0
		 * specification.  This requires a JSON response with and "error" field
		 * and optional description and URI fields.
		 *
		 * @param String	$error	A string representing one of the error types
		 * 							specified in s5.2 of the OAuth 2.0 spec, eg
		 * 							'invalid_request' or 'invalid_scope'.
		 */
		protected function sendErrorResponse($error = GoAuth2::ERROR_INVALID_REQUEST) {
			// Create the JSON response object
			$error_object = array(
				'error' 			=> $error,
				'error_description'	=> GoAuth2::getErrorDescription($error)
			);

			// Append the error URI if defined
			if(isset($this->error_uris[$error])) {
				$error_object['error_uri'] = $this->error_uris[$error];
			}

			// Encode the error into JSON
			$error_json = json_encode($error_object);

			// Send an HTTP 400 response
			$this->sendResponse(GOAuth2::HTTP_400, $error_json);
		}

		/**
		 * Send an HTTP response to the client.
		 *
		 * @param 	int 	$status			The code of the HTTP response code to send.
		 * @param	String	$response		The body of the response.
		 * @param	String	$content_type	Optional .The content type of the response.
		 * 									Defaults to 'application/json'.
		 */
		private function sendResponse($status, $response, $content_type = GOAuth2::CONTENT_TYPE_JSON, $no_store = false) {
			// Clean the output buffer to eliminate any whitespace.
			@ob_end_clean();

			// Set the response status code
			header($status);

			// Set the content type of the response
			header("Content-Type: $content_type");

			// Set the Cache-Control: no-store header if desired
			if($no_store) {
				header("Cache-Control: no-store");
			}

			// Send the response text
			echo $response;

			// Cease processing
			exit;
		}


		/**
		 * Authenticate the given client credentials.  This function MUST be
		 * reimplemented in the inheriting server subclass if that server
		 * utilises the client credentials authentication method. The function
		 * implementation MUST call the sendErrorResponse() method on a failed
		 * authentication.
		 *
		 * @param String	$client_id
		 * @param String	$client_secret
		 * @param String	$scope
		 */
		protected function authenticateClientCredentials($client_id, $client_secret, $scope = null) {
			throw new Exception('authenticateClientCredentials() not implemented by server.');
		}


		/**
		 * Generate and store an access token for the given client with
		 * the given scope.  This function MUST be reimplemented in the
		 * inheriting subclass.
		 *
		 * @param	String	$client_id	The ID of the client to be given
		 * 								the token.
		 * @param	String	$for_user	Optional. If given, represents
		 * 								the username of the resource
		 * 								owner on whose behalf the token
		 * 								is being generated.
		 * @param	String	$scope		Optional. If given, an array of
		 * 								permission scopes this token
		 * 								should represent.
		 */
		protected function generateAccessToken($client_id, $for_user = null, $scope = null) {
			throw new Exception('generateAccessToken() not implemented by server.');
		}


		/**
		 * Refresh and store an access token for the given client with
		 * the given scope.  This function MUST be reimplemented in the
		 * inheriting subclass.
		 *
		 * @param	String	$client_id		The ID of the client to be given
		 * 									the token.
		 * @param	String	$refresh_token	The refresh token provided by
		 * 									the client.
		 * @param	String	$scope			Optional. If given, an array of
		 * 									permission scopes this token
		 * 									should represent.
		 */
		protected function refreshAccessToken($client_id, $refresh_token, $scope = null) {
			throw new Exception('refreshAccessToken() not implemented by server.');
		}


		/**
		 * Validate the given resource owner credentials. This function MUST be
		 * reimplemented in the inheriting subclass _if_ the server needs to
		 * support this flow of access token grant.
		 *
		 * The OAuth specification notes that this flow should only be used
		 * where there is a high level of trust between the resource owner
		 * and the client, and should only be used where other flows aren't
		 * available. It is also used when migrating a client from a
		 * stored-password approach to an access token approach.
		 *
		 * @param	String	$client_id		The ID of the client to be given
		 * 									the token.
		 * @param	String	$refresh_token	The refresh token provided by
		 * 									the client.
		 * @param	array	$scope			Optional. If given, an array of
		 * 									permission scopes this token
		 * 									should represent.
		 */
		protected function validateResourceOwnerCredentials($username, $password) {
			throw new Exception('validateResourceOwnerCredentials() not implemented by server.');
		}
	}