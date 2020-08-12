<?php

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Docker\Container\WtfWarningFilter;
use Keboola\DockerBundle\Tests\BaseContainerTest;

class WtfWarningFilterTest extends BaseContainerTest
{
    public function testFilterEmptyResult()
    {
        $message = 'WARNING: Your kernel does not support swap limit capabilities or the cgroup is not mounted. Memory limited without swap.' .
            "\n" . "\n";
        $result = WtfWarningFilter::filter($message);
        self::assertEquals(false, $result); // there are some conditions like `if message` or `if process->getOutput`
        self::assertEquals('', $result);
    }

    public function testFilterNonEmptyResult()
    {
        $message = "WARNING: Your kernel does not support swap limit capabilities or the cgroup is not mounted. Memory limited without swap.\n[2020-08-10 13:00:18] CRITICAL: Symfony\\Component\\Process\\Exception\\ProcessFailedException:The command \"'kubectl' '--kubeconfig' '\/tmp\/run-5f3144e178b725.47882231\/5f3144e1df7f9--kubeconfig' 'delete' 'pod' 'python-134261' '--namespace' 'sandbox'\" failed.  Exit Code: 1(General error)  Working directory: \/code  Output: ================   Error Output: ================ Error from server (NotFound): pods \"python-134261\" not found  {\"errFile\":\"\/code\/vendor\/symfony\/process\/Process.php\",\"errLine\":256,\"errCode\":0,\"errTrace\":\"#0 \/code\/src\/Service\/Kubernetes\/Handler.php(82): Symfony\\\\Component\\\\Process\\\\Process->mustRun()\\n#1 \/code\/src\/Service\/Kubernetes\/Handler.php(100): Keboola\\\\Sandboxes\\\\Service\\\\Kubernetes\\\\Handler->runKubectl(Array, 500)\\n#2 \/code\/src\/Service\/ContainerManager.php(162): Keboola\\\\Sandboxes\\\\Service\\\\Kubernetes\\\\Handler->deletePhysicalSandbox(Object(Keboola\\\\Sandboxes\\\\Api\\\\Sandbox))\\n#3 \/code\/src\/App.php(188): Keboola\\\\Sandboxes\\\\Service\\\\ContainerManager->delete(Object(Keboola\\\\Sandboxes\\\\Api\\\\Sandbox))\\n#4 \/code\/src\/Component.php(66): Keboola\\\\Sandboxes\\\\App->terminate(Array)\\n#5 \/code\/vendor\/keboola\/php-component\/src\/BaseComponent.php(205): Keboola\\\\Sandboxes\\\\Component->run()\\n#6 \/code\/src\/run.php(14): Keboola\\\\Component\\\\BaseComponent->execute()\\n#7 {main}\",\"errPrevious\":\"\"} []";
        $expectedMessage = "[2020-08-10 13:00:18] CRITICAL: Symfony\\Component\\Process\\Exception\\ProcessFailedException:The command \"'kubectl' '--kubeconfig' '\/tmp\/run-5f3144e178b725.47882231\/5f3144e1df7f9--kubeconfig' 'delete' 'pod' 'python-134261' '--namespace' 'sandbox'\" failed.  Exit Code: 1(General error)  Working directory: \/code  Output: ================   Error Output: ================ Error from server (NotFound): pods \"python-134261\" not found  {\"errFile\":\"\/code\/vendor\/symfony\/process\/Process.php\",\"errLine\":256,\"errCode\":0,\"errTrace\":\"#0 \/code\/src\/Service\/Kubernetes\/Handler.php(82): Symfony\\\\Component\\\\Process\\\\Process->mustRun()\\n#1 \/code\/src\/Service\/Kubernetes\/Handler.php(100): Keboola\\\\Sandboxes\\\\Service\\\\Kubernetes\\\\Handler->runKubectl(Array, 500)\\n#2 \/code\/src\/Service\/ContainerManager.php(162): Keboola\\\\Sandboxes\\\\Service\\\\Kubernetes\\\\Handler->deletePhysicalSandbox(Object(Keboola\\\\Sandboxes\\\\Api\\\\Sandbox))\\n#3 \/code\/src\/App.php(188): Keboola\\\\Sandboxes\\\\Service\\\\ContainerManager->delete(Object(Keboola\\\\Sandboxes\\\\Api\\\\Sandbox))\\n#4 \/code\/src\/Component.php(66): Keboola\\\\Sandboxes\\\\App->terminate(Array)\\n#5 \/code\/vendor\/keboola\/php-component\/src\/BaseComponent.php(205): Keboola\\\\Sandboxes\\\\Component->run()\\n#6 \/code\/src\/run.php(14): Keboola\\\\Component\\\\BaseComponent->execute()\\n#7 {main}\",\"errPrevious\":\"\"} []";
        $result = WtfWarningFilter::filter($message);
        self::assertEquals($expectedMessage, $result);
    }

    public function testNoFilter()
    {
        $message = "[2020-08-10 13:00:18] CRITICAL: Symfony\\Component\\Process\\Exception\\ProcessFailedException:The command \"'kubectl' '--kubeconfig' '\/tmp\/run-5f3144e178b725.47882231\/5f3144e1df7f9--kubeconfig' 'delete' 'pod' 'python-134261' '--namespace' 'sandbox'\" failed.  Exit Code: 1(General error)  Working directory: \/code  Output: ================   Error Output: ================ Error from server (NotFound): pods \"python-134261\" not found  {\"errFile\":\"\/code\/vendor\/symfony\/process\/Process.php\",\"errLine\":256,\"errCode\":0,\"errTrace\":\"#0 \/code\/src\/Service\/Kubernetes\/Handler.php(82): Symfony\\\\Component\\\\Process\\\\Process->mustRun()\\n#1 \/code\/src\/Service\/Kubernetes\/Handler.php(100): Keboola\\\\Sandboxes\\\\Service\\\\Kubernetes\\\\Handler->runKubectl(Array, 500)\\n#2 \/code\/src\/Service\/ContainerManager.php(162): Keboola\\\\Sandboxes\\\\Service\\\\Kubernetes\\\\Handler->deletePhysicalSandbox(Object(Keboola\\\\Sandboxes\\\\Api\\\\Sandbox))\\n#3 \/code\/src\/App.php(188): Keboola\\\\Sandboxes\\\\Service\\\\ContainerManager->delete(Object(Keboola\\\\Sandboxes\\\\Api\\\\Sandbox))\\n#4 \/code\/src\/Component.php(66): Keboola\\\\Sandboxes\\\\App->terminate(Array)\\n#5 \/code\/vendor\/keboola\/php-component\/src\/BaseComponent.php(205): Keboola\\\\Sandboxes\\\\Component->run()\\n#6 \/code\/src\/run.php(14): Keboola\\\\Component\\\\BaseComponent->execute()\\n#7 {main}\",\"errPrevious\":\"\"} []";
        $result = WtfWarningFilter::filter($message);
        self::assertEquals($message, $result);
    }

}
