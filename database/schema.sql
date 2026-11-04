<?php
/**
 * database/schema.sql
 * Referência das tabelas MySQL usadas pelo sistema.
 * IMPORTANTE: o usuário já possui as tabelas criadas no banco na nuvem.
 * Este arquivo serve apenas como documentação da estrutura esperada
 * e para criar um ambiente de testes local.
 */

-- CREATE DATABASE saas_boletos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE saas_boletos;

-- ------------------------------------------------------------
-- Fornecedores (migrado de FORNBOL.DBF)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fornecedores (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    cod_for     VARCHAR(10)  NOT NULL UNIQUE,
    fan_for     VARCHAR(50)  NOT NULL,
    raz_for     VARCHAR(50)  NOT NULL DEFAULT '',
    dir_bol     VARCHAR(255) NOT NULL DEFAULT '',
    email_for   VARCHAR(100) NOT NULL DEFAULT '',
    email_for_2 VARCHAR(100) NOT NULL DEFAULT '',
    email_for_3 VARCHAR(100) NOT NULL DEFAULT '',
    email_for_4 VARCHAR(100) NOT NULL DEFAULT '',
    email_for_5 VARCHAR(100) NOT NULL DEFAULT '',
    email_for_6 VARCHAR(100) NOT NULL DEFAULT '',
    ativo       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cod_for (cod_for),
    INDEX idx_fan_for (fan_for)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Log de upload de boletos (auditoria)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_upload (
    id                BIGINT AUTO_INCREMENT PRIMARY KEY,
    fornecedor_id     INT NULL,
    cod_for           VARCHAR(10) NULL,
    arquivo_origem    VARCHAR(255) NOT NULL,
    arquivo_destino   VARCHAR(255) NULL,
    tamanho           INT UNSIGNED NULL,
    status            ENUM('pendente','sucesso','erro') NOT NULL DEFAULT 'pendente',
    mensagem_erro     TEXT NULL,
    data_processamento DATETIME NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_data (data_processamento),
    CONSTRAINT fk_log_upload_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Log de envios (fila de trabalho + histórico)
-- estado = 'pendente' -> fila aguardando o worker
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS envios_log (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    upload_id       BIGINT NULL,
    fornecedor_id   INT NULL,
    cod_for         VARCHAR(10) NULL,
    fan_for         VARCHAR(50) NULL,
    destino         VARCHAR(100) NULL,
    anexos          TEXT NULL,
    assunto         VARCHAR(255) NULL,
    estado          ENUM('pendente','enviado','erro') NOT NULL DEFAULT 'pendente',
    mensagem_erro   TEXT NULL,
    data_tentativa  DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_estado (estado),
    INDEX idx_data (data_tentativa),
    INDEX idx_cod_for (cod_for),
    CONSTRAINT fk_envios_fornecedor FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Configuração do sistema (substitui CAMINHO.DBF)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracao (
    chave      VARCHAR(50) PRIMARY KEY,
    valor      TEXT NOT NULL,
    descricao  TEXT NULL
) ENGINE=InnoDB;

-- Chaves sugeridas (valores sensíveis ficam no .env, não aqui)
INSERT IGNORE INTO configuracao (chave, valor, descricao) VALUES
    ('cc_fixo', 'contato@exemplo.com.br', 'E-mail sempre em cópia (CC fixo)'),
    ('intervalo_envio', '2', 'Segundos de espera entre envios (legado wenvio)'),
    ('intervalo_reenvio', '5', 'Segundos de espera entre reenvios (legado reenvio)');

-- ------------------------------------------------------------
-- Usuários do sistema (autenticação por e-mail corporativo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nome        VARCHAR(100) NOT NULL,
    email       VARCHAR(100) NOT NULL UNIQUE,
    senha       VARCHAR(255) NOT NULL,
    nivel       ENUM('completo','read') NOT NULL DEFAULT 'read',
    ativo       TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB;

-- Usuario inicial: NAO ha senha padrao versionada.
-- Gere o hash localmente e insira o administrador manualmente:
--   php -r "echo password_hash('SUA_SENHA_FORTE', PASSWORD_DEFAULT), PHP_EOL;"
-- INSERT INTO usuarios (nome, email, senha, nivel, ativo)
-- VALUES ('Administrador', 'contato@exemplo.com.br', '<HASH_GERADO>', 'completo', 1);
