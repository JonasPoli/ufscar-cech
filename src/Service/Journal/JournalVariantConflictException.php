<?php

declare(strict_types=1);

namespace App\Service\Journal;

/**
 * O nome que se tentou gravar como variante já pertence a outro periódico (outra família): é o título de uma linha
 * ou a variante de outra (docs/evolutions/periodicos-por-base.md, A5.3). Nada é gravado; o chamador mostra
 * `getMessage()` e o usuário remove a variação do periódico onde ela está ou mescla os periódicos.
 */
class JournalVariantConflictException extends \RuntimeException
{
    /**
     * @param array{kind: string, journalId: int, title: string} $conflict onde o nome já existe (ver JournalVariantGuard::findConflict)
     * @param string $name nome como foi digitado/recebido (para a mensagem)
     */
    public function __construct(
        private readonly array $conflict,
        private readonly string $name = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct(self::message($name, $conflict), 0, $previous);
    }

    /**
     * Mensagem para o usuário, também útil a quem só consultou `JournalVariantGuard::findConflict()` e não quer lançar.
     *
     * @param array{kind: string, journalId: int, title: string} $conflict
     */
    public static function message(string $name, array $conflict): string
    {
        return sprintf(
            'A variação «%s» já pertence ao periódico #%d «%s». Remova-a daqui ou mescle os periódicos.',
            $name,
            $conflict['journalId'],
            $conflict['title']
        );
    }

    /**
     * @return array{kind: string, journalId: int, title: string} `kind` = `variant` (variante de outro periódico) ou `title` (título de outra linha)
     */
    public function getConflict(): array
    {
        return $this->conflict;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
