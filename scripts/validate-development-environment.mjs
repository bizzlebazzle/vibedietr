import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';

const requiredValues = {
    APP_NAME: 'VibeDietr',
    APP_ENV: 'local',
    APP_URL: 'http://localhost',
    NODE_VERSION: '22',
    DB_CONNECTION: 'mysql',
    DB_HOST: 'mysql',
    DB_PORT: '3306',
    DB_DATABASE: 'vibedietr',
    DB_USERNAME: 'sail',
    DB_PASSWORD: 'password',
    SESSION_DRIVER: 'database',
    FILESYSTEM_DISK: 'local',
    QUEUE_CONNECTION: 'database',
    CACHE_STORE: 'database',
    REDIS_HOST: 'redis',
    MAIL_MAILER: 'smtp',
    MAIL_HOST: 'mailpit',
    MAIL_PORT: '1025',
};

const intentionallyBlankExternalCredentials = [
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'RESEND_KEY',
    'POSTMARK_TOKEN',
    'GOOGLE_APPLICATION_CREDENTIALS',
    'ADMIN_SECURITY_FINGERPRINT_KEY',
    'ADMIN_SECURITY_PROVIDER',
];

export function parseEnvironment(text) {
    const values = new Map();

    for (const [index, line] of text.split(/\r?\n/u).entries()) {
        if (line === '' || line.startsWith('#')) {
            continue;
        }

        const match = line.match(/^([A-Z][A-Z0-9_]*)=(.*)$/u);

        if (match === null) {
            throw new Error(`.env.example line ${index + 1} is malformed: ${line}`);
        }

        const [, key, rawValue] = match;

        if (values.has(key)) {
            throw new Error(`.env.example defines ${key} more than once`);
        }

        let value = rawValue;

        if (
            value.length >= 2
            && ((value.startsWith('"') && value.endsWith('"'))
                || (value.startsWith("'") && value.endsWith("'")))
        ) {
            value = value.slice(1, -1);
        }

        values.set(key, value);
    }

    return values;
}

export function composeServices(text) {
    const services = new Set();
    let insideServices = false;

    for (const line of text.split(/\r?\n/u)) {
        if (line === 'services:') {
            insideServices = true;
            continue;
        }

        if (insideServices && /^\S/u.test(line)) {
            break;
        }

        const match = insideServices ? line.match(/^ {4}([a-zA-Z0-9._-]+):\s*$/u) : null;

        if (match !== null) {
            services.add(match[1]);
        }
    }

    return services;
}

export function validateDevelopmentEnvironment(environmentText, composeText) {
    const environment = parseEnvironment(environmentText);
    const services = composeServices(composeText);
    const errors = [];

    for (const [key, expected] of Object.entries(requiredValues)) {
        const actual = environment.get(key);

        if (actual !== expected) {
            errors.push(`${key} must be ${JSON.stringify(expected)}; found ${JSON.stringify(actual)}`);
        }
    }

    for (const key of intentionallyBlankExternalCredentials) {
        if (environment.get(key) !== '') {
            errors.push(`${key} must remain blank in .env.example`);
        }
    }

    for (const hostKey of ['DB_HOST', 'REDIS_HOST', 'MAIL_HOST']) {
        const service = environment.get(hostKey);

        if (service !== undefined && !services.has(service)) {
            errors.push(`${hostKey} references missing Compose service ${JSON.stringify(service)}`);
        }
    }

    const requiredComposeFragments = [
        "context: './vendor/laravel/sail/runtimes/8.4'",
        "NODE_VERSION: '${NODE_VERSION:-22}'",
        "image: 'mysql/mysql-server:8.0'",
        "MYSQL_DATABASE: '${DB_DATABASE}'",
        "MYSQL_USER: '${DB_USERNAME}'",
        "MYSQL_PASSWORD: '${DB_PASSWORD}'",
        "'${APP_PORT:-80}:80'",
        "'${VITE_PORT:-5173}:${VITE_PORT:-5173}'",
    ];

    for (const fragment of requiredComposeFragments) {
        if (!composeText.includes(fragment)) {
            errors.push(`docker-compose.yml is missing expected baseline fragment: ${fragment}`);
        }
    }

    if (errors.length > 0) {
        throw new Error(errors.join('\n'));
    }
}

export async function validateDevelopmentEnvironmentFiles(root = process.cwd()) {
    const [environmentText, composeText] = await Promise.all([
        readFile(`${root}/.env.example`, 'utf8'),
        readFile(`${root}/docker-compose.yml`, 'utf8'),
    ]);

    validateDevelopmentEnvironment(environmentText, composeText);
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        await validateDevelopmentEnvironmentFiles();
        console.log('Development environment configuration is aligned.');
    } catch (error) {
        console.error(error instanceof Error ? error.message : error);
        process.exitCode = 1;
    }
}
