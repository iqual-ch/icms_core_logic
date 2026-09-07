<?php

declare(strict_types=1);

namespace Drupal\Tests\icms_core_logic\ExistingSite;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests `drush icms:post-deploy`.
 */
#[Group('icms_core_logic')]
class PostDeployCommandTest extends ExistingSiteBase {

  /**
   * The command runs its steps and reports them.
   */
  public function testRunsAndReportsSteps(): void {
    $process = new Process([DRUPAL_ROOT . '/../vendor/bin/drush', 'icms:post-deploy', '--skip=purge'], DRUPAL_ROOT);
    $process->setTimeout(600)->run();
    $output = $process->getOutput() . $process->getErrorOutput();

    $this->assertSame(0, $process->getExitCode(), $output);
    foreach (\Drupal::languageManager()->getLanguages() as $language) {
      $this->assertStringContainsString('language "' . $language->getId() . '"', $output);
    }
    $this->assertStringContainsString('Step "warm" finished', $output);
    $this->assertStringNotContainsString('Step "purge"', $output);
    $this->assertStringContainsString('Post-deploy finished', $output);
  }

  /**
   * Unknown steps are rejected.
   */
  public function testRejectsUnknownStep(): void {
    $process = new Process([DRUPAL_ROOT . '/../vendor/bin/drush', 'icms:post-deploy', '--skip=nope'], DRUPAL_ROOT);
    $process->setTimeout(120)->run();

    $this->assertNotSame(0, $process->getExitCode());
    $this->assertStringContainsString('Unknown post-deploy step(s): nope', $process->getOutput() . $process->getErrorOutput());
  }

}
