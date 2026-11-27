<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Rate limits for the public endpoints (config/packages/rate_limiter.yaml): asking a question
 * (POST /ask) and the MCP server (/mcp). Runs after routing, so it keys on route names.
 */
final readonly class RateLimitListener
{
    public function __construct(
        #[Autowire(service: 'limiter.ask_visitor')] private RateLimiterFactoryInterface $askVisitor,
        #[Autowire(service: 'limiter.ask_site')] private RateLimiterFactoryInterface $askSite,
        #[Autowire(service: 'limiter.mcp_visitor')] private RateLimiterFactoryInterface $mcpVisitor,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsEventListener(priority: 16)]
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $limit = match ($request->attributes->get('_route')) {
            'ask_post' => $this->firstRejected(
                $this->askVisitor->create(self::visitor($request))->consume(),
                $this->askSite->create('site')->consume(),
            ),
            '_mcp_endpoint_packages' => 'OPTIONS' === $request->getMethod() ? null
                : $this->firstRejected($this->mcpVisitor->create(self::visitor($request))->consume()),
            default => null,
        };
        if (null === $limit) {
            return;
        }

        $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
        $message = sprintf('Too many questions right now. Please try again in %s.', $retry < 120 ? $retry.' seconds' : (int) ceil($retry / 60).' minutes');
        // The ask form is a browser page: say so on it. MCP clients get a plain 429.
        if ('ask_post' === $request->attributes->get('_route') && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('warning', $message);
            $event->setResponse(new RedirectResponse($this->urls->generate('ask')));

            return;
        }
        $event->setResponse(new Response(
            str_replace('questions', 'requests', $message),
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retry, 'Content-Type' => 'text/plain; charset=utf-8'],
        ));
    }

    private function firstRejected(RateLimit ...$limits): ?RateLimit
    {
        foreach ($limits as $limit) {
            if (!$limit->isAccepted()) {
                return $limit;
            }
        }

        return null;
    }

    /**
     * Behind Cloudflare and Dokku's nginx, getClientIp() is a Cloudflare edge address, so prefer
     * the visitor address Cloudflare sends. A client reaching the origin directly can set that
     * header to pick its bucket; the site-wide /ask cap still holds.
     */
    private static function visitor(Request $request): string
    {
        return $request->headers->get('CF-Connecting-IP') ?: ($request->getClientIp() ?? 'unknown');
    }
}
