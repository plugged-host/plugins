<?php

namespace PluggedHost\Ai\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders Markdown images as their alt text. neo's answers can be steered by text it reads
 * (console, files, pastes), and an image would be fetched by the browser as soon as the
 * answer shows, sending whatever the model put in its URL to that host without a click.
 */
final class ImagesAsTextExtension implements ExtensionInterface, NodeRendererInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(Image::class, $this, 100);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Image::assertInstanceOf($node);

        return $childRenderer->renderNodes($node->children());
    }
}
