<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class TelegramController extends Controller
{
    /**
     * @Route("/webhook/tgbot", name="weebhook_tgbot")
     * @param Request $request
     * @return JsonResponse
     */
    public function telegram(Request $request): JsonResponse
    {

        $result = json_decode($request->getContent(), true);


//        https://api.telegram.org/bot[ (link is external)далее токен бота без пробелов]/setWebhook?url=https://[адрес, на который вы хотите получать данные от telegram API]
//        https://api.telegram.org/bot[ (link is external)далее токен бота без пробелов]/getWebhookInfo



        return new JsonResponse('ok');

//        return $response;
    }
}
