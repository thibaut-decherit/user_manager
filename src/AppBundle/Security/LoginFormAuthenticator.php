<?php

namespace AppBundle\Security;

use AppBundle\Entity\User;
use AppBundle\Helper\StringHelper;
use AppBundle\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Class LoginFormAuthenticator
 *
 * See https://symfony.com/doc/current/security/guard_authentication.html#the-guard-authenticator-methods
 *
 * @package AppBundle\Security
 */
class LoginFormAuthenticator extends AbstractFormLoginAuthenticator
{
    use TargetPathTrait;

    private $em;
    private $router;
    private $passwordEncoder;
    private $csrfTokenManager;
    private $translatorInterface;
    private $sessionInterface;
    private $mailer;

    /**
     * LoginFormAuthenticator constructor.
     * @param EntityManagerInterface $em
     * @param RouterInterface $router
     * @param UserPasswordEncoderInterface $passwordEncoder
     * @param CsrfTokenManagerInterface $csrfTokenManager
     * @param TranslatorInterface $translatorInterface
     * @param SessionInterface $sessionInterface
     * @param MailerService $mailer
     */
    public function __construct(
        MailerService $mailer,
        EntityManagerInterface $em,
        RouterInterface $router,
        UserPasswordEncoderInterface $passwordEncoder,
        CsrfTokenManagerInterface $csrfTokenManager,
        TranslatorInterface $translatorInterface,
        SessionInterface $sessionInterface
    )
    {
        $this->em = $em;
        $this->router = $router;
        $this->passwordEncoder = $passwordEncoder;
        $this->csrfTokenManager = $csrfTokenManager;
        $this->translatorInterface = $translatorInterface;
        $this->sessionInterface = $sessionInterface;
        $this->mailer = $mailer;
    }

    /**
     * @param Request $request
     * @return array|mixed|void
     */
    public function getCredentials(Request $request)
    {
        $loginCheckRoute = $this->router->generate('login');

        $isLoginSubmit = $request->getPathInfo() == $loginCheckRoute && $request->isMethod('POST');

        if (!$isLoginSubmit) {
            // skip authentication
            return;
        }

        $username = StringHelper::truncateToMySQLVarcharMaxLength($request->get('_username'));
        $password = StringHelper::truncateToPasswordEncoderMaxLength($request->get('_password'));
        $csrfToken = $request->get('_csrf_token');

        if (false === $this->csrfTokenManager->isTokenValid(new CsrfToken('login', $csrfToken))) {
            throw new InvalidCsrfTokenException();
        }

        $request->getSession()->set(
            Security::LAST_USERNAME,
            $username
        );

        return [
            'username' => $username,
            'password' => $password
        ];
    }

    /**
     * This is not limited by UserProvider methods. (e.g UserProvider doesn't have a method to find user by email but
     * guard is still able to do so)
     *
     * @param mixed $credentials
     * @param UserProviderInterface $userProvider
     * @return User|object|UserInterface|null
     */
    public function getUser($credentials, UserProviderInterface $userProvider): ?object
    {
        $usernameOrEmail = $credentials['username'];

        if (preg_match('/^.+@\S+\.\S+$/', $usernameOrEmail)) {
            return $this->em->getRepository('AppBundle:User')->findOneBy(['email' => $usernameOrEmail]);
        }

        return $this->em->getRepository('AppBundle:User')->findOneBy(['username' => $usernameOrEmail]);
    }

    /**
     * @param mixed $credentials
     * @param UserInterface $user
     * @return bool
     */
    public function checkCredentials($credentials, UserInterface $user): bool
    {
        $password = $credentials['password'];

        if ($this->passwordEncoder->isPasswordValid($user, $password)) {
            return true;
        }

        return false;
    }

    /**
     * @param Request $request
     * @param TokenInterface $token
     * @param string $providerKey
     * @return JsonResponse
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey): JsonResponse
    {
        $targetPath = null;
        // if the user hits a secure page and start() was called, this was
        // the URL they were on, and probably where you want to redirect to
        $targetPath = $this->getTargetPath($request->getSession(), $providerKey);

        if (!$targetPath) {
            $targetPath = $this->router->generate('home');
        }

        return new JsonResponse(['url' => $targetPath], 200);
    }

    /**
     * @param Request $request
     * @param AuthenticationException $exception
     * @return JsonResponse
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        // IF account is not yet activated, send a reminder email with an activation link
        if ($exception instanceof DisabledException) {
            $usernameOrEmail = $request->request->get('_username');
            $user = null;

            if (preg_match('/^.+@\S+\.\S+$/', $usernameOrEmail)) {
                $user = $this->em->getRepository('AppBundle:User')->findOneBy(['email' => $usernameOrEmail]);
            } else {
                $user = $this->em->getRepository('AppBundle:User')->findOneBy(['username' => $usernameOrEmail]);
            }

            $this->mailer->loginAttemptOnNonActivatedAccount($user);
        }

        $errorMessage = $this->translatorInterface->trans('flash.user.invalid_credentials');

        return new JsonResponse([
            'errorMessage' => $errorMessage
        ], 400);
    }

    /**
     * @return string
     */
    protected function getLoginUrl(): string
    {
        return $this->router->generate('login');
    }

    /**
     * This is called if the client accesses a URI/resource that requires authentication, but no authentication details were sent.
     *
     * @param Request $request
     * @param AuthenticationException|null $exception
     * @return RedirectResponse
     */
    public function start(Request $request, AuthenticationException $exception = null): RedirectResponse
    {
        $this->sessionInterface->getFlashBag()->add(
            'login-required-error',
            $this->translatorInterface->trans('flash.user.login_required')
        );
        $url = $this->router->generate('login');

        return new RedirectResponse($url);
    }

    /**
     * If you want to support "remember me" functionality, return true from this method
     *
     * @return bool
     */
    public function supportsRememberMe(): bool
    {
        return true;
    }
}
