<?php

namespace App\Http\Grant;

use Illuminate\Http\Request;
use App\Models\SocialAccount;
use Laravel\Passport\Bridge\User;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class SocialGrant extends AbstractGrant
{
    /**
     * @param UserRepositoryInterface         $userRepository
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     */
    public function __construct(
        UserRepositoryInterface         $userRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository
    ) {
        $this->setUserRepository($userRepository);
        $this->setRefreshTokenRepository($refreshTokenRepository);

        $this->refreshTokenTTL = new \DateInterval('P1M');
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return 'social';
    }

    /**
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface  $responseType,
        \DateInterval          $accessTokenTTL
    ) {
        // Validate request
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request));
        $user   = $this->validateUser($request, $client);

        // Finalize the requested scopes
        $scopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $user->getIdentifier());

        // Issue and persist new tokens
        $accessToken  = $this->issueAccessToken($accessTokenTTL, $client, $user->getIdentifier(), $scopes);
        $refreshToken = $this->issueRefreshToken($accessToken);

        // Inject tokens into response
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        return $responseType;
    }

    /**
     * @param  ServerRequestInterface $request
     * @param  ClientEntityInterface  $client
     * @throws OAuthServerException
     * @return UserEntityInterface
     */
    protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client)
    {
        $provider = $this->getRequestParameter('provider', $request);

        if (null === $provider) {
            throw OAuthServerException::invalidRequest('provider');
        }

        $provider_user_id = $this->getRequestParameter('provider_user_id', $request);

        if (null === $provider_user_id) {
            throw OAuthServerException::invalidRequest('provider_user_id');
        }

        $user = $this->getUserFromSocialNetwork(new Request($request->getParsedBody()));

        if ($user instanceof UserEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidCredentials();
        }

        return $user;
    }

    /**
     * @param Request $request
     * @return null
     */
    private function getUserFromSocialNetwork(Request $request)
    {
        $provider = config('auth.guards.api.provider');

        if (null === $model = config('auth.providers.'.$provider.'.model')) {
            throw new RuntimeException('Unable to determine authentication model from configuration.');
        }

        $socialAccount = SocialAccount::where('provider', $request->provider)->where('provider_user_id', $request->provider_user_id)->first();

        if (!$socialAccount) {
            return;
        }

        $user = $socialAccount->user()->first();

        if (!$user) {
            return;
        }

        return new User($user->getAuthIdentifier());
    }
}