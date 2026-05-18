import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync, cpSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import YAML from 'yaml';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');
const configPath = path.join(projectRoot, 'openapi', 'internal-api-client.config.json');

function readConfig() {
    return JSON.parse(readFileSync(configPath, 'utf8'));
}

function buildRawSpecUrl(config) {
    const repositoryPath = config.source.repositoryUrl
        .replace('https://github.com/', '')
        .replace(/\.git$/, '');

    return `https://raw.githubusercontent.com/${repositoryPath}/${config.source.commit}/${config.source.specPath}`;
}

async function fetchSpec(specUrl) {
    const response = await fetch(specUrl);

    if (!response.ok) {
        throw new Error(`Failed to fetch OpenAPI spec from ${specUrl}: HTTP ${response.status}`);
    }

    return YAML.parse(await response.text());
}

function ensureSet(map, key) {
    if (!map.has(key)) {
        map.set(key, new Set());
    }

    return map.get(key);
}

function createComponentRefStore() {
    return new Map();
}

function addComponentRef(componentRefs, ref) {
    const match = /^#\/components\/([^/]+)\/([^/]+)$/.exec(ref);

    if (!match) {
        return;
    }

    const [, kind, name] = match;
    ensureSet(componentRefs, kind).add(name);
}

function collectRefs(componentRefs, value) {
    if (!value) {
        return;
    }

    if (Array.isArray(value)) {
        for (const entry of value) {
            collectRefs(componentRefs, entry);
        }

        return;
    }

    if (typeof value !== 'object') {
        return;
    }

    if (typeof value.$ref === 'string') {
        addComponentRef(componentRefs, value.$ref);
        return;
    }

    for (const entry of Object.values(value)) {
        collectRefs(componentRefs, entry);
    }
}

function collectSecuritySchemeNames(spec, selectedOperations) {
    const schemeNames = new Set();

    const collectFromRequirements = (requirements) => {
        if (!Array.isArray(requirements)) {
            return;
        }

        for (const requirement of requirements) {
            if (!requirement || typeof requirement !== 'object') {
                continue;
            }

            for (const schemeName of Object.keys(requirement)) {
                schemeNames.add(schemeName);
            }
        }
    };

    collectFromRequirements(spec.security);

    for (const operation of selectedOperations) {
        collectFromRequirements(operation.security);
    }

    return schemeNames;
}

function cloneReferencedComponents(spec, componentRefs) {
    const sourceComponents = spec.components || {};
    const clonedComponents = {};
    const processed = new Map();

    const queue = [];

    for (const [kind, names] of componentRefs.entries()) {
        for (const name of names) {
            queue.push([kind, name]);
        }
    }

    while (queue.length > 0) {
        const [kind, name] = queue.shift();
        const seenForKind = ensureSet(processed, kind);

        if (seenForKind.has(name)) {
            continue;
        }

        seenForKind.add(name);

        const sourceKind = sourceComponents[kind];

        if (!sourceKind || !(name in sourceKind)) {
            throw new Error(`Referenced component ${kind}/${name} was not found in the upstream spec.`);
        }

        if (!clonedComponents[kind]) {
            clonedComponents[kind] = {};
        }

        const component = sourceKind[name];
        clonedComponents[kind][name] = component;
        collectRefs(componentRefs, component);

        const updatedNames = componentRefs.get(kind);

        if (updatedNames) {
            for (const updatedName of updatedNames) {
                if (!seenForKind.has(updatedName)) {
                    queue.push([kind, updatedName]);
                }
            }
        }

        for (const [queuedKind, queuedNames] of componentRefs.entries()) {
            if (queuedKind === kind) {
                continue;
            }

            const seenQueuedKind = ensureSet(processed, queuedKind);

            for (const queuedName of queuedNames) {
                if (!seenQueuedKind.has(queuedName)) {
                    queue.push([queuedKind, queuedName]);
                }
            }
        }
    }

    return clonedComponents;
}

function filterSpec(spec, operationIds) {
    const selectedOperations = [];
    const usedTags = new Set();
    const componentRefs = createComponentRefStore();
    const filteredPaths = {};

    for (const [pathName, pathItem] of Object.entries(spec.paths || {})) {
        const filteredPathItem = {};

        for (const [method, operation] of Object.entries(pathItem)) {
            if ('parameters' === method || !operation || typeof operation !== 'object') {
                continue;
            }

            if (!operationIds.includes(operation.operationId)) {
                continue;
            }

            filteredPathItem[method] = operation;
            selectedOperations.push(operation);
            collectRefs(componentRefs, operation);

            for (const tag of operation.tags || []) {
                usedTags.add(tag);
            }
        }

        if (Object.keys(filteredPathItem).length === 0) {
            continue;
        }

        if (pathItem.parameters) {
            filteredPathItem.parameters = pathItem.parameters;
            collectRefs(componentRefs, pathItem.parameters);
        }

        filteredPaths[pathName] = filteredPathItem;
    }

    const securitySchemeNames = collectSecuritySchemeNames(spec, selectedOperations);
    const filteredComponents = cloneReferencedComponents(spec, componentRefs);

    if (securitySchemeNames.size > 0 && spec.components?.securitySchemes) {
        filteredComponents.securitySchemes = {};

        for (const schemeName of securitySchemeNames) {
            if (spec.components.securitySchemes[schemeName]) {
                filteredComponents.securitySchemes[schemeName] = spec.components.securitySchemes[schemeName];
            }
        }
    }

    return {
        openapi: spec.openapi,
        info: spec.info,
        servers: spec.servers || [],
        paths: filteredPaths,
        components: filteredComponents,
        tags: Array.isArray(spec.tags)
            ? spec.tags.filter((tag) => usedTags.has(tag.name))
            : [],
    };
}

function writeFilteredSpec(config, filteredSpec, specUrl) {
    const filteredSpecPath = path.join(projectRoot, config.filteredSpecPath);
    const filteredSpecDirectory = path.dirname(filteredSpecPath);

    mkdirSync(filteredSpecDirectory, { recursive: true });

    const header = [
        '# Filtered OpenAPI spec for Aura Historia Partner Connect.',
        '# Generated by scripts/generate-openapi-client.mjs.',
        `# Source: ${specUrl}`,
        '',
    ].join('\n');

    writeFileSync(filteredSpecPath, header + YAML.stringify(filteredSpec), 'utf8');
}

function removePath(config, relativePath) {
    const absolutePath = path.join(projectRoot, relativePath);

    try {
        rmSync(absolutePath, { recursive: true, force: true });
        return;
    } catch (error) {
        // Fall through to Docker cleanup when the generated files are root-owned.
    }

    execFileSync(
        'docker',
        [
            'run',
            '--rm',
            '-v',
            `${projectRoot}:/local`,
            'alpine:3.22',
            'rm',
            '-rf',
            `/local/${relativePath}`,
        ],
        {
            cwd: projectRoot,
            stdio: 'inherit',
        },
    );
}

function runGenerator(config) {
    removePath(config, config.temporaryPath);

    const dockerArgs = [
        'run',
        '--rm',
        '-v',
        `${projectRoot}:/local`,
        config.generator.image,
        'generate',
        '-g',
        config.generator.name,
        '-i',
        `/local/${config.filteredSpecPath}`,
        '-o',
        `/local/${config.temporaryPath}`,
        '--global-property',
        [
            'apis',
            'models',
            'supportingFiles',
            'apiDocs=false',
            'apiTests=false',
            'modelDocs=false',
            'modelTests=false',
        ].join(','),
        '--additional-properties',
        [
            `invokerPackage=${config.namespace}`,
            `modelPackage=${config.modelPackage}`,
            'hideGenerationTimestamp=true',
            'library=guzzle',
        ].join(','),
    ];

    execFileSync('docker', dockerArgs, {
        cwd: projectRoot,
        stdio: 'inherit',
    });
}

function copyGeneratedClient(config) {
    const tempLibPath = path.join(projectRoot, config.temporaryPath, 'lib');
    const outputPath = path.join(projectRoot, config.outputPath);

    if (!existsSync(tempLibPath)) {
        throw new Error(`Expected generated client directory at ${tempLibPath}, but it was not found.`);
    }

    rmSync(outputPath, { recursive: true, force: true });
    mkdirSync(outputPath, { recursive: true });
    cpSync(tempLibPath, outputPath, { recursive: true });
}

function cleanupTemporaryOutput(config) {
    removePath(config, config.temporaryPath);
}

function replaceOrThrow(content, search, replacement, description) {
    if (!content.includes(search)) {
        throw new Error(`Could not apply ${description} patch to generated client.`);
    }

    return content.replace(search, replacement);
}

function replaceRegexOrThrow(content, pattern, replacement, description) {
    if (!pattern.test(content)) {
        throw new Error(`Could not apply ${description} patch to generated client.`);
    }

    return content.replace(pattern, replacement);
}

function applyWordPressGeneratedClientPatches(config) {
    const outputPath = path.join(projectRoot, config.outputPath);

    const configurationPath = path.join(outputPath, 'Configuration.php');
    let configuration = readFileSync(configurationPath, 'utf8');

    configuration = replaceOrThrow(
        configuration,
        String.raw`    public function __construct()
    {
        $this->tempFolderPath = sys_get_temp_dir();
    }
`,
        String.raw`    public function __construct()
    {
        $this->tempFolderPath = self::resolveManagedTempFolderPath();
    }
`,
        'Configuration constructor',
    );

    configuration = replaceOrThrow(
        configuration,
        String.raw`    public function setDebugFile($debugFile)
    {
        $this->debugFile = $debugFile;
        return $this;
    }
`,
        String.raw`    public function setDebugFile($debugFile)
    {
        $this->debugFile = self::normalizeDebugFilePath($debugFile);
        return $this;
    }
`,
        'Configuration debug file normalization',
    );

    configuration = replaceOrThrow(
        configuration,
        String.raw`    public function setTempFolderPath($tempFolderPath)
    {
        $this->tempFolderPath = $tempFolderPath;
        return $this;
    }
`,
        String.raw`    public function setTempFolderPath($tempFolderPath)
    {
        $this->tempFolderPath = self::resolveManagedTempFolderPath(
            $tempFolderPath,
        );
        return $this;
    }
`,
        'Configuration temp folder normalization',
    );

    configuration = replaceOrThrow(
        configuration,
        String.raw`    /**
     * Gets the default configuration instance
     *
     * @return Configuration
     */
    public static function getDefaultConfiguration()
`,
        String.raw`    /**
     * Resolves the managed uploads subdirectory used for generated client files.
     *
     * @param string $subdirectory Optional relative subdirectory name.
     *
     * @return string
     */
    private static function resolveManagedTempFolderPath($subdirectory = "")
    {
        $base_directory = self::getManagedStorageBasePath();
        $subdirectory = self::sanitizeRelativePath($subdirectory);

        if ("" === $base_directory || "" === $subdirectory) {
            return $base_directory;
        }

        $directory = $base_directory . DIRECTORY_SEPARATOR . $subdirectory;
        self::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Resolves the managed storage base path under the WordPress uploads directory.
     *
     * @return string
     */
    private static function getManagedStorageBasePath()
    {
        $base_directory = "";

        if (function_exists("\\wp_upload_dir")) {
            $uploads = call_user_func("\\wp_upload_dir");

            if (
                is_array($uploads) &&
                empty($uploads["error"]) &&
                !empty($uploads["basedir"])
            ) {
                $base_directory = (string) $uploads["basedir"];
            }
        }

        if ("" === $base_directory && defined("WP_CONTENT_DIR") && "" !== WP_CONTENT_DIR) {
            $base_directory =
                rtrim(WP_CONTENT_DIR, "/\\") .
                DIRECTORY_SEPARATOR .
                "uploads";
        }

        if ("" === $base_directory) {
            return "";
        }

        $managed_directory =
            rtrim($base_directory, "/\\") .
            DIRECTORY_SEPARATOR .
            "aura-historia-partner-connect" .
            DIRECTORY_SEPARATOR .
            "internal-api";

        self::ensureDirectoryExists($managed_directory);

        return $managed_directory;
    }

    /**
     * Restricts debug output to PHP streams or the managed uploads directory.
     *
     * @param string $debugFile Requested debug file path.
     *
     * @return string
     */
    private static function normalizeDebugFilePath($debugFile)
    {
        $debugFile = trim((string) $debugFile);

        if ("" === $debugFile) {
            return "php://output";
        }

        if (
            in_array(
                $debugFile,
                ["php://output", "php://stdout", "php://stderr"],
                true,
            )
        ) {
            return $debugFile;
        }

        $filename = basename(str_replace("\\", "/", $debugFile));
        $filename = self::sanitizePathSegment($filename);

        if ("" === $filename) {
            $filename = "debug.log";
        }

        $managed_directory = self::getManagedStorageBasePath();

        if ("" === $managed_directory) {
            return "php://output";
        }

        return $managed_directory . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Sanitizes a relative path so it remains inside the managed uploads directory.
     *
     * @param string $path Requested relative path.
     *
     * @return string
     */
    private static function sanitizeRelativePath($path)
    {
        $path = str_replace("\\", "/", trim((string) $path));

        if ("" === $path) {
            return "";
        }

        $segments = [];

        foreach (explode("/", $path) as $segment) {
            $segment = self::sanitizePathSegment($segment);

            if ("" !== $segment && "." !== $segment && ".." !== $segment) {
                $segments[] = $segment;
            }
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Sanitizes a single path segment for use in the managed uploads directory.
     *
     * @param string $segment Requested path segment.
     *
     * @return string
     */
    private static function sanitizePathSegment($segment)
    {
        $segment = trim((string) $segment);

        if ("" === $segment) {
            return "";
        }

        if (function_exists("\\sanitize_file_name")) {
            return (string) call_user_func("\\sanitize_file_name", $segment);
        }

        return (string) preg_replace("/[^A-Za-z0-9._-]/", "-", $segment);
    }

    /**
     * Creates the managed uploads directory when needed.
     *
     * @param string $directory Directory path.
     *
     * @return void
     */
    private static function ensureDirectoryExists($directory)
    {
        if (
            "" === $directory ||
            is_dir($directory) ||
            !function_exists("\\wp_mkdir_p")
        ) {
            return;
        }

        call_user_func("\\wp_mkdir_p", $directory);
    }

    /**
     * Gets the default configuration instance
     *
     * @return Configuration
     */
    public static function getDefaultConfiguration()
`,
        'Configuration uploads directory helpers',
    );

    writeFileSync(configurationPath, configuration, 'utf8');

    const objectSerializerPath = path.join(outputPath, 'ObjectSerializer.php');
    let objectSerializer = readFileSync(objectSerializerPath, 'utf8');

    objectSerializer = replaceRegexOrThrow(
        objectSerializer,
        /[ \t]*\/\/ determine file name[\s\S]*?return new \\SplFileObject\(\$filename, ['"]r['"]\);\n/m,
        String.raw`            $temp_folder_path = Configuration::getDefaultConfiguration()->getTempFolderPath();

            if ("" === $temp_folder_path) {
                throw new \RuntimeException(
                    "Failed to resolve a writable uploads directory for temporary API response files.",
                );
            }

            // determine file name
            if (
                is_array($httpHeaders) &&
                array_key_exists("Content-Disposition", $httpHeaders) &&
                preg_match(
                    '/inline; filename=[\'"]?([^\'"\s]+)[\'"]?$/i',
                    $httpHeaders["Content-Disposition"],
                    $match,
                )
            ) {
                $sanitized_filename = self::sanitizeFilename($match[1]);

                if (function_exists("\\sanitize_file_name")) {
                    $sanitized_filename = (string) call_user_func(
                        "\\sanitize_file_name",
                        $sanitized_filename,
                    );
                }

                $filename =
                    "" === $sanitized_filename
                        ? ""
                        : rtrim($temp_folder_path, "/\\") .
                            DIRECTORY_SEPARATOR .
                            $sanitized_filename;
            } else {
                $filename = "";
            }

            if ("" === $filename) {
                if (function_exists("\\wp_tempnam")) {
                    $filename = call_user_func(
                        "\\wp_tempnam",
                        "ahpc-response.tmp",
                        $temp_folder_path,
                    );
                } else {
                    $filename =
                        rtrim($temp_folder_path, "/\\") .
                        DIRECTORY_SEPARATOR .
                        uniqid("ahpc-", true) .
                        ".tmp";
                }
            }

            if (!is_string($filename) || "" === $filename) {
                throw new \RuntimeException(
                    "Failed to create a temporary file for the API response body.",
                );
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Response bodies are stored only in the managed uploads temp directory.
            $file = fopen($filename, "wb");
            while ($chunk = $data->read(200)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Response bodies are stored only in the managed uploads temp directory.
                fwrite($file, $chunk);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Response bodies are stored only in the managed uploads temp directory.
            fclose($file);

            return new \SplFileObject($filename, "rb");
`,
        'ObjectSerializer temp file handling',
    );

    writeFileSync(objectSerializerPath, objectSerializer, 'utf8');
}

async function main() {
    const config = readConfig();
    const specUrl = buildRawSpecUrl(config);

    console.log(`Fetching pinned OpenAPI spec from ${specUrl}`);

    const upstreamSpec = await fetchSpec(specUrl);
    const filteredSpec = filterSpec(upstreamSpec, config.operations);

    writeFilteredSpec(config, filteredSpec, specUrl);

    console.log(`Generating PHP client with ${config.generator.image}`);

    runGenerator(config);
    copyGeneratedClient(config);
    applyWordPressGeneratedClientPatches(config);
    cleanupTemporaryOutput(config);

    console.log(`Updated generated OpenAPI client in ${config.outputPath}`);
}

main().catch((error) => {
    console.error(error instanceof Error ? error.message : error);
    process.exit(1);
});
