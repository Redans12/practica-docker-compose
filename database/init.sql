CREATE TABLE IF NOT EXISTS reservaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    espacio VARCHAR(80) NOT NULL,
    solicitante VARCHAR(100) NOT NULL,
    correo VARCHAR(120) NOT NULL,
    fecha DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    estado ENUM('pendiente','confirmada','cancelada') NOT NULL DEFAULT 'pendiente',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_horas CHECK (hora_fin > hora_inicio),
    CONSTRAINT uq_espacio_horario UNIQUE (espacio, fecha, hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO reservaciones (espacio, solicitante, correo, fecha, hora_inicio, hora_fin, estado) VALUES
('Laboratorio 3', 'Ana Torres',  'ana@ejemplo.com',  '2026-10-20', '09:00', '11:00', 'confirmada'),
('Auditorio',     'Luis Pérez',  'luis@ejemplo.com', '2026-10-21', '12:00', '14:00', 'pendiente'),
('Sala de juntas','Marta Ruiz',  'marta@ejemplo.com','2026-10-22', '16:00', '17:30', 'pendiente');