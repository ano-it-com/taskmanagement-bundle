<?php

namespace ANOITCOM\TaskmanagementBundle\Services\WikiPageBlock\BlockRenderHandlers\Handlers;

use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\WikiPage\Category;
use ANOITCOM\Wiki\Entity\WikiPage\WikiPage;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlock;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageHtmlBlock;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageObjectBlock;
use ANOITCOM\Wiki\Services\PageQuery\PageQueryRender;
use Doctrine\ORM\EntityManagerInterface;
use function Doctrine\ORM\QueryBuilder;

class RegionIdGeneratorHandler implements HandlerInterface
{

    private $em;

    /**
     * @var PageQueryRender
     */
    private $render;


    public function __construct(EntityManagerInterface $entityManager, PageQueryRender $render)
    {
        $this->em     = $entityManager;
        $this->render = $render;
    }


    /**
     *
     * @param WikiPageHtmlBlock $block
     *
     * @return WikiPageBlock|null
     */
    public function handle(WikiPageBlock $block): ?WikiPageBlock
    {
        $html = $block->getValue()->getValue();

        $pregMatch = '/\%region%([\s\S]+?)\%%/';

        $matches      = [];
        $matchesCount = preg_match_all($pregMatch, $html, $matches);

        if (empty($matches[0])) {
            return $block;
        }

        foreach ($matches[1] as $key => $match) {
            $match    = urldecode($match);
            $category = $this->parseCategory($match, $block);

            $categoryId = $category ? $category->getId() : '';

            $html = str_replace($matches[0][$key], $categoryId, $html);
        }

        $block->getValue()->setValue($html);

        return $block;
    }


    private function parseCategory($match, WikiPageBlock $block): ?Category
    {
        /** @var WikiPage $page */
        $page = $block->getPage();

        $category = null;

        foreach ($page->getBlocks() as $wikiPageBlock) {
            if ( ! $wikiPageBlock instanceof WikiPageObjectBlock) {
                continue;
            }

            foreach ($wikiPageBlock->getObjects() as $wikiPageObject) {
                if ( ! $wikiPageObject->getType()) {
                    continue;
                }

                if ($wikiPageObject->getType()->getTitle() === $match) {
                    $category = $this->em->getRepository(Category::class)->findOneBy([
                        'title' => $wikiPageObject->getTitle()
                    ]);
                }
            }
        }

        return $category;
    }


    public function support(WikiPageBlock $block): bool
    {
        return $block instanceof WikiPageHtmlBlock;
    }


    public function getSort(): int
    {
        return 15;
    }
}
