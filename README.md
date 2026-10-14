# Práctica Docker Compose – Cinco Servicios

Sistema de **Reservación de espacios** (dominio asignado por número de control terminado en 8) orquestado con Docker Compose.

**Autor:** Andres Ibarrola – Ing. en Sistemas Computacionales, TecNM Morelia
**Materia:** Infraestructura de Servicios

## Arquitectura

| Servicio | Imagen | Puerto | Publicado | Función |
|---|---|---|---|---|
| frontend | nginx:alpine (build) | 80 | 8081 | Interfaz web y proxy inverso a `/api/` |
| backend | php:8.3-apache (build) | 80 | No | API REST (PHP + PDO) |
| db | mariadb:11 | 3306 | No | Base de datos |
| redis | redis:7-alpine | 6379 | No | Caché y contador de consultas |
| mailpit | axllent/mailpit:v1.31.4 | 1025 / 8025 | 8025 | Correos de prueba |

Todos los servicios están en la red `app_network` y se comunican por nombre (`db`, `redis`, `mailpit`, `backend`) gracias al DNS interno de Docker. Solo se publican los puertos 8081 y 8025.

## Requisitos

- Docker Desktop (Docker Compose v2)
- Git

## Cómo ejecutarlo

1. Clonar el repositorio:

```bash
git clone https://github.com/Redans12/practica-docker-compose.git
cd practica-docker-compose
```

2. Crear el archivo de variables de entorno a partir del ejemplo y cambiar las contraseñas:

```powershell
copy .env.example .env
```

3. Construir y levantar los servicios:

```bash
docker compose up -d --build --wait
```

4. Verificar:

```bash
docker compose ps
```

## Accesos

- Aplicación: http://localhost:8081
- Salud del backend: http://localhost:8081/api/health
- Mailpit (correos): http://localhost:8025

## Variables de entorno (`.env`)

| Variable | Descripción |
|---|---|
| DB_NAME, DB_USER, DB_PASSWORD, DB_ROOT_PASSWORD | Credenciales de MariaDB |
| DB_HOST=db, DB_PORT=3306 | Ubicación de la base de datos |
| REDIS_HOST=redis, REDIS_PORT=6379 | Ubicación de Redis |
| SMTP_HOST=mailpit, SMTP_PORT=1025 | Servidor SMTP de pruebas |
| MAIL_FROM | Remitente de los correos |

`.env` no se versiona (está en `.gitignore`); usar `.env.example` como plantilla.

## API

| Método | Ruta | Descripción |
|---|---|---|
| GET | /health | Estado del backend |
| GET | /reservaciones | Lista (con caché; cabecera `X-Cache: MISS/HIT`) |
| POST | /reservaciones | Crear (envía correo a Mailpit) |
| GET | /reservaciones/{id} | Consultar una |
| PUT | /reservaciones/{id} | Editar |
| DELETE | /reservaciones/{id} | Eliminar |

Códigos de respuesta: 200, 201, 404, 405, 409 (conflicto de horario), 422 (datos inválidos), 500.

## Funcionamiento de la caché

- La lista se guarda en Redis con la clave `reservaciones:lista` y TTL de 30 segundos.
- Cada consulta incrementa `contador_consultas`.
- Al crear, editar o eliminar se invalida la caché.

## Mejora individual: validación avanzada

- Reglas de longitud y caracteres permitidos.
- Validación de formato de correo.
- Detección de traslape de horarios (mismo espacio y fecha): responde 409; las reservaciones adyacentes se permiten y las canceladas no bloquean.
- El frontend resalta en rojo los campos con error.

## Pruebas útiles

```bash
# Cache MISS / HIT
curl.exe -i http://localhost:8081/api/reservaciones

# Claves en Redis
docker compose exec redis redis-cli keys "*"

# Tabla en MariaDB
docker compose exec db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT * FROM reservaciones;"'
```

## Persistencia

Los datos se guardan en los volúmenes `mariadb_data` y `redis_data`.

```bash
docker compose down      # conserva los datos
docker compose down -v   # elimina los datos (init.sql se ejecuta de nuevo)
```

## Estructura

```
.
├── compose.yaml
├── .env.example
├── .gitignore
├── README.md
├── backend/   (Dockerfile, src/)
├── frontend/  (Dockerfile, nginx.conf, src/)
└── database/  (init.sql)
```