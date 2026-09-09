<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\Unit;

use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\icms_core_logic\BackendDomain;
use Drupal\icms_core_logic\EventSubscriber\BackendDomainSubscriber;
use Drupal\Tests\UnitTestCase;
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

  private function subscriber(?string $routeName = NULL, array $parameters = [], ?BackendDomain $domain = NULL): BackendDomainSubscriber {
    $routeMatch = $this->createMock(RouteMatchInterface::class);
    $routeMatch->method('getRouteName')->willReturn($routeName);
    $routeMatch->method('getParameters')->willReturn(new ParameterBag($parameters));
    return new BackendDomainSubscriber($domain ?? new BackendDomain('https://www.example.com', 'https://backend.example.com'), $routeMatch);
  }

  private function requestEvent(Request $request): RequestEvent {
    return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
  }

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

    // Single-domain setups never redirect.
    $event = $this->requestEvent(Request::create('https://www.example.com/node/1'));
    $this->subscriber('entity.node.canonical', ['node' => $this->entityWithUrl('/de/ueber-uns')], new BackendDomain(NULL, NULL))->onRequest($event);
    $this->assertNull($event->getResponse());
  }

  /**
   * Backend responses may be framed by the frontend and are not indexed.
   */
  public function testAdjustsBackendResponseHeaders(): void {
    $response = new Response();
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('https://backend.example.com/user/login'), HttpKernelInterface::MAIN_REQUEST, $response);
    $this->subscriber()->onResponse($event);

    $this->assertFalse($response->headers->has('X-Frame-Options'));
    $this->assertSame("frame-ancestors 'self' https://www.example.com", $response->headers->get('Content-Security-Policy'));
    $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));

    // An existing policy is kept.
    $response = new Response();
    $response->headers->set('Content-Security-Policy', "default-src 'self'");
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('https://backend.example.com/user/login'), HttpKernelInterface::MAIN_REQUEST, $response);
    $this->subscriber()->onResponse($event);
    $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
  }

  /**
   * Responses to frontend requests keep their headers.
   */
  public function testLeavesFrontendResponsesAlone(): void {
    $response = new Response();
    $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
    $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('https://www.example.com/sitemap.xml'), HttpKernelInterface::MAIN_REQUEST, $response);
    $this->subscriber()->onResponse($event);

    $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    $this->assertFalse($response->headers->has('X-Robots-Tag'));
  }

}
