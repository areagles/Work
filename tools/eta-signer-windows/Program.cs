using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.Json;

var builder = WebApplication.CreateBuilder(args);

builder.Services.ConfigureHttpJsonOptions(options =>
{
    options.SerializerOptions.PropertyNamingPolicy = JsonNamingPolicy.CamelCase;
    options.SerializerOptions.WriteIndented = false;
});

var app = builder.Build();

var signerConfig = app.Configuration.GetSection("Signer");
var signerApiKey = signerConfig["ApiKey"] ?? string.Empty;
var allowedOrigins = signerConfig.GetSection("AllowedOrigins").Get<string[]>() ?? Array.Empty<string>();
var useMockSigner = bool.TryParse(signerConfig["UseMockSigner"], out var parsedMock) && parsedMock;
var certificateThumbprint = (signerConfig["CertificateThumbprint"] ?? string.Empty).Trim();

app.MapGet("/health", () => Results.Ok(new
{
    ok = true,
    service = "arab-eagles-eta-signer",
    mode = useMockSigner ? "mock" : "certificate",
    hasCertificateThumbprint = !string.IsNullOrWhiteSpace(certificateThumbprint)
}));

app.MapPost("/sign", async (HttpContext httpContext) =>
{
    if (string.IsNullOrWhiteSpace(signerApiKey))
    {
        return Results.Json(new { ok = false, error = "signer_api_key_missing" }, statusCode: 500);
    }

    var authHeader = httpContext.Request.Headers.Authorization.ToString();
    var expectedBearer = $"Bearer {signerApiKey}";
    if (!string.Equals(authHeader, expectedBearer, StringComparison.Ordinal))
    {
        return Results.Json(new { ok = false, error = "unauthorized" }, statusCode: 401);
    }

    SignRequest? request;
    try
    {
        request = await httpContext.Request.ReadFromJsonAsync<SignRequest>();
    }
    catch
    {
        return Results.Json(new { ok = false, error = "invalid_json" }, statusCode: 400);
    }

    if (request is null || request.Document.ValueKind is JsonValueKind.Undefined or JsonValueKind.Null)
    {
        return Results.Json(new { ok = false, error = "missing_document" }, statusCode: 422);
    }

    if (request.Document.TryGetProperty("signatures", out _))
    {
        return Results.Json(new { ok = false, error = "document_already_contains_signatures" }, statusCode: 422);
    }

    if (useMockSigner)
    {
        var mockSignature = Convert.ToBase64String(Encoding.UTF8.GetBytes("MOCK-ETA-SIGNATURE"));
        var mockSigned = AppendSignature(request.Document, mockSignature);
        return Results.Ok(new
        {
            ok = true,
            mode = "mock",
            document = mockSigned,
            signature = mockSignature
        });
    }

    if (string.IsNullOrWhiteSpace(certificateThumbprint))
    {
        return Results.Json(new { ok = false, error = "certificate_thumbprint_missing" }, statusCode: 500);
    }

    try
    {
        using var certificate = FindCertificateByThumbprint(certificateThumbprint);
        if (certificate is null)
        {
            return Results.Json(new { ok = false, error = "certificate_not_found" }, statusCode: 500);
        }

        var documentJson = request.Document.GetRawText();
        var canonicalBytes = Encoding.UTF8.GetBytes(documentJson);
        var hashBytes = SHA256.HashData(canonicalBytes);
        var signatureBytes = SignHashWithCertificate(certificate, hashBytes);
        var signatureBase64 = Convert.ToBase64String(signatureBytes);
        var signedDocument = AppendSignature(request.Document, signatureBase64);

        return Results.Ok(new
        {
            ok = true,
            mode = "certificate",
            document = signedDocument,
            signature = signatureBase64
        });
    }
    catch (Exception ex)
    {
        return Results.Json(new
        {
            ok = false,
            error = "signing_failed",
            message = ex.Message
        }, statusCode: 500);
    }
});

app.Run(signerConfig["BindUrl"] ?? "http://0.0.0.0:8080");

static JsonElement AppendSignature(JsonElement originalDocument, string signatureBase64)
{
    using var doc = JsonDocument.Parse(originalDocument.GetRawText());
    var root = doc.RootElement;
    var map = new Dictionary<string, object?>(StringComparer.Ordinal);
    foreach (var property in root.EnumerateObject())
    {
        map[property.Name] = JsonSerializer.Deserialize<object?>(property.Value.GetRawText());
    }

    map["signatures"] = new object[]
    {
        new Dictionary<string, object?>
        {
            ["signatureType"] = "I",
            ["value"] = signatureBase64
        }
    };

    var json = JsonSerializer.Serialize(map);
    using var signedDoc = JsonDocument.Parse(json);
    return signedDoc.RootElement.Clone();
}

static X509Certificate2? FindCertificateByThumbprint(string thumbprint)
{
    var normalized = thumbprint.Replace(" ", string.Empty, StringComparison.Ordinal).ToUpperInvariant();
    using var store = new X509Store(StoreName.My, StoreLocation.CurrentUser);
    store.Open(OpenFlags.ReadOnly);
    var matches = store.Certificates.Find(X509FindType.FindByThumbprint, normalized, validOnly: false);
    return matches.Count > 0 ? matches[0] : null;
}

static byte[] SignHashWithCertificate(X509Certificate2 certificate, byte[] hashBytes)
{
    using var rsa = certificate.GetRSAPrivateKey();
    if (rsa is null)
    {
        throw new InvalidOperationException("The configured certificate does not expose an RSA private key through the current Egypt Trust setup.");
    }

    return rsa.SignHash(hashBytes, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
}

public sealed class SignRequest
{
    public string Mode { get; set; } = string.Empty;
    public string Hash { get; set; } = string.Empty;
    public JsonElement Document { get; set; }
}
