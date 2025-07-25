CREATE DATABASE IF NOT EXISTS avp2_backend;

USE avp2_backend;

CREATE TABLE IF NOT EXISTS produtos (
    id CHAR(36) PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    tipo VARCHAR(255),
    valor DECIMAL(10, 2) NOT NULL,
    CONSTRAINT chk_valor_positivo CHECK (valor >= 0)
);

CREATE TABLE IF NOT EXISTS juros (
    id INT PRIMARY KEY AUTO_INCREMENT,
    taxa_selic DECIMAL(10, 4) NOT NULL,
    data_inicio DATE NOT NULL,
    data_final DATE NOT NULL,
    ultima_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO juros (taxa_selic, data_inicio, data_final) VALUES (0.00, '2010-01-01', '2010-01-01');

CREATE TABLE IF NOT EXISTS compras (
    id CHAR(36) PRIMARY KEY,
    id_produto CHAR(36) NOT NULL,
    valor_entrada DECIMAL(10, 2) NOT NULL,
    qtd_parcelas INT NOT NULL,
    valor_parcela DECIMAL(10, 2) NOT NULL,
    taxa_juros DECIMAL(10, 2) NOT NULL,
    data_compra TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_produto
        FOREIGN KEY (id_produto) 
        REFERENCES produtos(id)
        ON DELETE RESTRICT
);