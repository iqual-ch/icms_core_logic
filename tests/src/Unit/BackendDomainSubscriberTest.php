<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\EventSubscriber\BackendDomainSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the backend domain request and response handling.
 */
#[Group('icms_core_logic')]
class BackendDomainSubscriberTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Cacheable redirect responses validate their cache contexts.
    $cacheContextsManager = $this->createMock(CacheContextsManager::class);
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);
  }

  /**
   * Creates the subscriber for a route match.
   */
  private function subscriber(?string $routeName = NULL, array $parameters = []): BackendDomainSubscriber {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($routeName);
    $routeMatch->method('getParameters')->willReturn(new ParameterBag($parameters));
    return new BackendDomainSubscriber(new BackendDomain(new Settings(['icms_frontend_hosts' => ['www.example.com']])), $routeMatch);
  }

  /**
   * Creates a main request event.
   */
  private function requestEvent(Request $request): RequestEvent {
    return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
  }

  /**
   * Creates a main response event for a request to the given URI.
   */
  private function responseEvent(string $uri, Response $response): ResponseEvent {
    return new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create($uri), HttpKernelInterface::MAIN_REQUEST, $response);
  }

  /**
   * Creates an entity whose canonical URL is the given path.
   */
  private function entityWithUrl(string $url): EntityInterface {
    $urlObject = $this->createMock(Url::class);
    $urlObject->method('toString')->with(TRUE)->willReturn((new GeneratedUrl())->setGeneratedUrl($url));
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('toUrl')->willReturn($urlObject);
    return $entity;
  }

  /**
   * Content pages on the backend domain redirect to the frontend alias.
   */
  public function testRedirectsContentPagesToTheFrontend(): void {
    $event = $this->requestEvent(Request::create('https://backend.example.com/node/1?preview=1'));
    $this->subscriber('entity.node.canonical', ['node' => $this->entityWithUrl('/de/ueber-uns')])->onRequest($event);

    $this->assertNotNull($event->getResponse());
    $this->assertSame(302, $event->getResponse()->getStatusCode());
    $this->assertSame('https://www.example.com/de/ueber-uns?preview=1', $event->getResponse()->headers->get('Location'));

    // The target is the frontend of the domain requested.
    $event = $this->requestEvent(Request::create('https://backend.example.org/node/1'));
    $this->subscriber('entity.node.canonical', ['node' => $this->entityWithUrl('/de/ueber-uns')])->onRequest($event);
    $this->assertSame('https://example.org/de/ueber-uns', $event->getResponse()?->headers->get('Location'));
  }

  /**
   * The front page redirects as requested, the frontend negotiates it.
   */
  public function testRedirectsTheFrontPage(): void {
    foreach (['/' => 'https://www.example.com/', '/fr' => 'https://www.example.com/fr', '/de/' => 'https://www.example.com/de/'] as $path => $expected) {
      $event = $this->requestEvent(Request::create('https://backend.example.com' . $path));
      $this->subscriber('entity.node.canonical', ['node' => $this->entityWithUrl('/de/startseite')])->onRequest($event);
      $this->assertSame($expected, $event->getResponse()?->headers->get('Location'), $path);
    }
  }

  /**
   * Admin pages, non-HTML requests and frontend requests are left alone.
   */
  public function testLeavesOtherRequestsAlone(): void {
    $event = $this->requestEvent(Request::create('https://backend.example.com/node/1/edit'));
    $this->subscriber('entity.node.edit_form', ['node' => $this->entityWithUrl('/de/ueber-uns')])->onRequest($event);
    $this->assertNull($event->getResponse());

    $request = Request::create('https://backend.example.com/node/1');
    $request->setRequestFormat('json');
    $event = $this->requestEvent($request);
    $this->subscriber('entity.node.canonical', ['node' => $this->entityWithUrl('/de/ueber-uns')])->onRequest($event);
    $this->assertNull($event->getResponse());

    $event = $this->requestEvent(Request::create('https://www.example.com/node/1'));
    $this->subscriber('entity.node.canonical', ['node' => $this->entityWithUrl('/de/ueber-uns')])->onRequest($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * Backend responses may be framed by the frontend and are not indexed.
   */
  public function testAdjustsBackendResponseHeaders(): void {
    $response = new Response();
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $this->subscriber()->onResponse($this->responseEvent('https://backend.example.com/user/login', $response));

    // X-Frame-Options stays as a fallback; CSP frame-ancestors wins where
    // supported.
    $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    $this->assertSame("frame-ancestors 'self' https://www.example.com", $response->headers->get('Content-Security-Policy'));
    $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

    // An existing X-Robots-Tag is kept.
    $response = new Response();
    $response->headers->set('X-Robots-Tag', 'none');
    $this->subscriber()->onResponse($this->responseEvent('https://backend.example.com/user/login', $response));
    $this->assertSame('none', $response->headers->get('X-Robots-Tag'));
  }

  /**
   * The frontend is merged into an existing policy, which is kept otherwise.
   */
  #[DataProvider('policyProvider')]
  public function testMergesFrameAncestorsIntoAnExistingPolicy(array|string $policy, array|string $expected): void {
    $response = new Response();
    $response->headers->set('Content-Security-Policy', $policy);
    $this->subscriber()->onResponse($this->responseEvent('https://backend.example.com/user/login', $response));
    $this->assertSame((array) $expected, $response->headers->all('Content-Security-Policy'));
  }

  /**
   * Data provider for testMergesFrameAncestorsIntoAnExistingPolicy().
   */
  public static function policyProvider(): array {
    return [
      'no frame-ancestors' => ["default-src 'self'", "default-src 'self'; frame-ancestors 'self' https://www.example.com"],
      'trailing semicolon' => ["default-src 'self'; ", "default-src 'self'; frame-ancestors 'self' https://www.example.com"],
      'none is replaced' => ["frame-ancestors 'none'", "frame-ancestors 'self' https://www.example.com"],
      'sources are kept' => [
        "default-src 'self'; frame-ancestors 'self' https://other.example.org; img-src *",
        "default-src 'self'; frame-ancestors 'self' https://other.example.org https://www.example.com; img-src *",
      ],
      'already allowed' => ["frame-ancestors https://www.example.com 'self'", "frame-ancestors https://www.example.com 'self'"],
      'several headers' => [
        ["default-src 'self'", "frame-ancestors 'self'"],
        ["default-src 'self'", "frame-ancestors 'self' https://www.example.com"],
      ],
    ];
  }

  /**
   * Responses to frontend requests keep their headers.
   */
  public function testLeavesFrontendResponsesAlone(): void {
    $response = new Response();
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $this->subscriber()->onResponse($this->responseEvent('https://www.example.com/sitemap.xml', $response));

    $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    $this->assertFalse($response->headers->has('Content-Security-Policy'));
    $this->assertFalse($response->headers->has('X-Robots-Tag'));
  }

}
