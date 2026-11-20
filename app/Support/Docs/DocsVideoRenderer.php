<?php

namespace App\Support\Docs;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\RegexHelper;

/**
 * Plays a video written like an image: ![What it shows](/storage/blog/clip.mp4).
 * Anything that isn't a video file falls through to the normal image renderer.
 */
final class DocsVideoRenderer implements NodeRendererInterface
{
    public const EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    public static function isVideo(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?HtmlElement
    {
        if (! $node instanceof Image || ! self::isVideo($node->getUrl()) || RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
            return null;
        }

        $label = '';
        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $label .= $child->getLiteral();
            }
        }

        $attrs = ['src' => $node->getUrl(), 'controls' => true, 'playsinline' => true, 'preload' => 'metadata'];
        if (trim($label) !== '') {
            $attrs['aria-label'] = trim($label);
        }
        if (($title = $node->getTitle()) !== null && $title !== '') {
            $attrs['title'] = $title;
        }

        return new HtmlElement('video', $attrs, '');
    }
}
