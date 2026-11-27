<?php

namespace App\Support\Integrations;

/**
 * One field of a provider's connect form. Secret fields are stored encrypted
 * with the account's credentials and are never sent back to the browser.
 */
final readonly class ConnectField
{
    /** @param  list<string>  $rules */
    public function __construct(
        public string $key,
        public string $label,
        public bool $secret = true,
        public ?string $help = null,
        public ?string $link = null,
        public ?string $linkLabel = null,
        public ?string $placeholder = null,
        public array $rules = ['required', 'string', 'max:500'],
        public ?string $value = null,
    ) {}

    /** @return array{key: string, label: string, secret: bool, help: string|null, link: string|null, linkLabel: string|null, placeholder: string|null, value: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'secret' => $this->secret,
            'help' => $this->help,
            'link' => $this->link,
            'linkLabel' => $this->linkLabel,
            'placeholder' => $this->placeholder,
            'value' => $this->secret ? null : $this->value,
        ];
    }
}
