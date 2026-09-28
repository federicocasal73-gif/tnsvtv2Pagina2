<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Audita accesos al fallback de autenticacion por user_code en headers/JSON/query.
 *
 * Contexto (ver audit AUDIT-2026-09-28 #3):
 * Multiples controllers exponen getCurrentUser() que cae de la sesion/JWT a un
 * "X-Game-Code" header o a un user_code enviado en el body/query. Esto existe
 * para compatibilidad con clientes mobile legacy, pero es un bypass potencial
 * de autenticacion: si el codigo es adivinable, un tercero puede leer journals
 * ajenos haciendose pasar por ese usuario.
 *
 * Por decision del owner (Fase 1.3), NO cambiamos el comportamiento — solo
 * dejamos huella digital de cada uso para detectar accesos anomalos
 * post-mortem y tener evidencia si el codigo se filtra.
 *
 * Politica de PII:
 *  - Solo guardamos los ULTIMOS 4 caracteres del codigo (sufijo).
 *    Suficiente para correlacionar con un user conocido, insuficiente para
 *    suplantarlo (los codigos visibles al cliente tienen 8+ chars).
 *  - IP + User-Agent para fingerprinting de cliente.
 *  - Ruta exacta + metodo HTTP para reproducir el request.
 */
final class AuthAuditLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Llamado desde cada getCurrentUser() de los controllers cuando se usa
     * el fallback en vez de la sesion/JWT.
     *
     * @param string $source Origen del codigo: 'X-Game-Code-header' | 'json-body' | 'query-string'
     */
    public function logFallbackUsage(Request $request, string $code, string $source): void
    {
        $suffix = $code !== '' ? substr($code, -4) : '';

        $this->logger->warning('auth_bypass_fallback_used', [
            'audit_type'   => 'auth_bypass_fallback',
            'source'       => $source,
            'code_suffix'  => $suffix,
            'method'       => $request->getMethod(),
            'path'         => $request->getPathInfo(),
            'ip'           => $request->getClientIp(),
            'user_agent'   => $request->headers->get('User-Agent'),
        ]);
    }
}