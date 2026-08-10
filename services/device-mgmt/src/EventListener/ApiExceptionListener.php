<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\DeviceConflictException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsEventListener]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        $status = match (true) {
            $exception instanceof NotFoundHttpException => Response::HTTP_NOT_FOUND,
            $exception instanceof DeviceConflictException => Response::HTTP_CONFLICT,
            $exception instanceof \JsonException => Response::HTTP_BAD_REQUEST,
            $exception instanceof \InvalidArgumentException => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => null,
        };

        if (null === $status) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => $exception->getMessage()], $status));
    }
}
