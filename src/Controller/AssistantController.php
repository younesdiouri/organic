<?php

namespace App\Controller;

use App\Service\Assistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;

final class AssistantController extends AbstractController
{
    #[Route('/assistant', name: 'assistant', methods: ['GET', 'POST'])]
    public function index(Request $request, Assistant $assistant, #[Target('assistant')] RateLimiterFactoryInterface $limiter): Response
    {
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'assistant_question'])
            ->add('question', TextareaType::class, ['label' => 'Votre question', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 1500)], 'attr' => ['rows' => 3, 'maxlength' => 1500, 'placeholder' => 'Quels sont les 3 plats au coût matière le plus élevé ?']])
            ->getForm()->handleRequest($request);
        $result = null;
        $status = 200;
        $headers = [];

        if ($form->isSubmitted()) {
            $status = 422;

            if ($form->isValid()) {
                $limit = $limiter->create('admin:'.$this->getUser()->getUserIdentifier())->consume();

                if (!$limit->isAccepted()) {
                    $status = 429;
                    $headers['Retry-After'] = (string) max(1, $limit->getRetryAfter()->getTimestamp() - time());
                    $form->addError(new FormError('Limite atteinte : 12 questions par 10 minutes. Réessayez un peu plus tard.'));
                } else {
                    try {
                        $result = $assistant->ask($form->getData()['question']);
                        $status = 200;
                    } catch (\RuntimeException $e) {
                        $status = 503;
                        $form->addError(new FormError($e->getMessage()));
                    }
                }
            }
        }
        $sources = $result['sources'] ?? [];

        return $this->render('assistant.html.twig', ['form' => $form, 'configured' => $assistant->configured(), 'answer' => $result['answer'] ?? null, 'notices' => $result['notices'] ?? [], 'sources' => array_slice($sources, 0, 30), 'sources_truncated' => count($sources) > 30], new Response(status: $status, headers: $headers));
    }
}
