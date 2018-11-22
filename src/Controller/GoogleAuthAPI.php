<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use Google_Client as GoogleClient;
use Google_Service_Calendar as GoogleServiceCalendar;

use Symfony\Component\DependencyInjection\ContainerInterface as Container;

class GoogleAuthAPI extends Controller
{
    protected $googleClient;
    protected $authUrl;
    protected $siteUrl;

    protected $rootPath;

    public function __construct(Container $container)
    {
        $this->rootPath = $container->getParameter('root_path');
        $this->siteUrl = 'https://' . $_SERVER['HTTP_HOST'];

        $this->googleClient = new GoogleClient;
        $this->googleClient->setAuthConfig($this->rootPath . '/client_secret.json');
        $this->googleClient->setAccessType("offline");
        $this->googleClient->setIncludeGrantedScopes(true);
        $this->googleClient->addScope(GoogleServiceCalendar::CALENDAR);
        $this->googleClient->setRedirectUri($this->siteUrl . $container->get('router')->generate('google_oauth2_callback'));

        $this->authUrl = $this->googleClient->createAuthUrl();
    }

    /**
     * @Route("/google/oauth2/auth", name="google_oauth2_auth")
     */
    public function oauth2Auth()
    {
        header('Location: ' . filter_var($this->authUrl, FILTER_SANITIZE_URL));
        return new Response();
    }

    /**
     * @Route("/google/oauth2/callback", name="google_oauth2_callback")
     */
    public function oauth2Callback()
    {
        if (!isset($_GET['code'])) {
            header('Location: ' . filter_var($this->authUrl, FILTER_SANITIZE_URL));
        } else {
            $this->googleClient->authenticate($_GET['code']);
            $_SESSION['access_token'] = $this->googleClient->getAccessToken();
            header('Location: ' . filter_var($this->siteUrl, FILTER_SANITIZE_URL));
        }

        return new Response();
    }

//
}