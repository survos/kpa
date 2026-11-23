<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cloudflare reaches production's nginx over plain HTTP, so nginx forwards "http" as the
 * scheme and generated absolute URLs (the RSS enclosures) would be http://. Cloudflare's
 * CF-Visitor header carries the visitor's real scheme; it is trusted only from the private
 * Docker network, where nginx is.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final class CloudflareSchemeListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $visitor = (string) $request->headers->get('CF-Visitor', '');
        if (!str_contains($visitor, '"https"') || !IpUtils::checkIp((string) $request->server->get('REMOTE_ADDR'), IpUtils::PRIVATE_SUBNETS)) {
            return;
        }
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('X-Forwarded-Port', '443');
        $request->server->set('HTTPS', 'on');
        $request->server->set('SERVER_PORT', '443');
    }
}
