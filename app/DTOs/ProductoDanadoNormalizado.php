<?php

namespace App\DTOs;

/**
 * Contrato común: el cuerpo de POST /api/products del backend del proyecto de aula
 * (ProductRequest). El traductor siempre produce esta estructura, venga de donde venga.
 */
final readonly class ProductoDanadoNormalizado
{
    public function __construct(
        public string $name,             // nombre del producto, sin espacios sobrantes
        public ?string $description,     // descripción opcional
        public float $price,             // número con máximo 2 decimales, en USD
        public int $stock,               // unidades recibidas
        public string $sku,              // número de guía S10: EC123456789EC
        public string $damageLevel,      // leve | moderado | severo
        public ?string $damageNotes,     // detalle del daño, opcional
        public string $status,           // published | draft
    ) {}

    /** @return array<string, mixed> Mismos nombres y orden que ProductRequest */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'price'       => $this->price,
            'stock'       => $this->stock,
            'sku'         => $this->sku,
            'damageLevel' => $this->damageLevel,
            'damageNotes' => $this->damageNotes,
            'status'      => $this->status,
        ];
    }

    /** Mismo mapeo que ProductRequest::attributesForModel(): contrato -> columnas de damaged_products. */
    public function attributesForModel(): array
    {
        return [
            'title'        => $this->name,
            'description'  => $this->description,
            'price'        => $this->price,
            'stock'        => $this->stock,
            'sku'          => $this->sku,
            'damage_level' => $this->damageLevel,
            'damage_notes' => $this->damageNotes,
            'status'       => $this->status,
        ];
    }
}
