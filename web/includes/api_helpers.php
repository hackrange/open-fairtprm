<?php
/**
 * Standardized API Response Helpers - Because Consistency Is Nice
 *
 * Author: Tim Rice
 * Disclaimer: Use this software at your own risk. No warranty provided.
 *
 * A tiny collection of utility functions for building consistent JSON API responses.
 * Instead of every endpoint hand-rolling its own json_encode + header + exit dance,
 * just call apiSuccessResponse() or apiErrorResponse() and be done with it.
 * These are totally optional -- existing APIs don't need to migrate, but new endpoints
 * should use these so we don't end up with 47 different ways to say "something broke."
 * Think of it as the API response equivalent of a style guide.
 */

/**
 * Builds a success response array. Slaps success=true on the front,
 * optionally adds a message, and merges in whatever extra data you pass.
 * Does NOT send the response -- just builds the array. Use apiSuccessResponse()
 * if you want the full "set header, encode, exit" treatment.
 */
function apiSuccess($data = [], $message = null) {
    $response = ['success' => true];
    if ($message !== null) {
        $response['message'] = $message;
    }
    // Merge in the extra data -- anything you pass shows up at the top level of the response
    return array_merge($response, $data);
}

/**
 * Builds an error response array and sets the HTTP status code.
 * Default status is 400 (Bad Request) because most API errors are "you sent us garbage."
 * For auth failures use 401, for permissions use 403, for "we screwed up" use 500.
 */
function apiError($message, $code = 400) {
    http_response_code($code);
    return ['success' => false, 'error' => $message];
}

/**
 * Sets the Content-Type to application/json.
 * One line, but you'd be surprised how often people forget this
 * and wonder why their frontend is trying to parse HTML as JSON.
 */
function apiJsonHeader() {
    header('Content-Type: application/json');
}

/**
 * The "fire and forget" response sender. Sets the JSON header, encodes
 * whatever you pass, echoes it, and exits. No code runs after this call.
 * If you need to do cleanup after sending the response... don't use this.
 */
function apiResponse($data) {
    apiJsonHeader();
    echo json_encode($data);
    exit;
}

/**
 * Convenience wrapper: build a success response AND send it in one call.
 * The most common pattern in API endpoints:
 *   apiSuccessResponse(['user' => $user], 'User created successfully');
 * That's it. Headers set, JSON sent, request done.
 */
function apiSuccessResponse($data = [], $message = null) {
    apiResponse(apiSuccess($data, $message));
}

/**
 * Same thing but for errors. Sets the HTTP status code, builds the error
 * response, encodes it, sends it, and exits. One line to tell the client
 * exactly what went wrong and how bad it is.
 */
function apiErrorResponse($message, $code = 400) {
    apiResponse(apiError($message, $code));
}
