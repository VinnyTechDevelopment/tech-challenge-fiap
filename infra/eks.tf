module "eks" {
  source  = "terraform-aws-modules/eks/aws"
  version = "~> 20.0"

  cluster_name    = "${var.project_name}-eks"
  cluster_version = var.cluster_version

  vpc_id     = module.vpc.vpc_id
  subnet_ids = module.vpc.private_subnets

  cluster_endpoint_public_access = true

  # --- AWS Academy: não é possível criar roles IAM novas, só existe a LabRole ---
  create_iam_role = false
  iam_role_arn    = var.lab_role_arn

  # IRSA cria um IAM OpenID Connect provider, o que também exige permissão
  # de criar recursos IAM que o Academy não libera. Desligado por enquanto.
  enable_irsa = false

  # O módulo cria uma KMS key pra criptografar secrets do cluster por padrão.
  # Deixamos desligado pra não depender de permissão de KMS no Academy.
  create_kms_key            = false
  cluster_encryption_config = {}

  eks_managed_node_group_defaults = {
    create_iam_role = false
    iam_role_arn    = var.lab_role_arn
  }

  eks_managed_node_groups = {
    default = {
      instance_types = var.node_instance_types
      min_size       = var.node_min_size
      max_size       = var.node_max_size
      desired_size   = var.node_desired_size
    }
  }

  # Sem EBS CSI driver: o MySQL virou RDS, não sobrou nenhum PV dentro do
  # cluster, então não precisamos desse addon (e ele pediria IRSA/IAM extra).
  cluster_addons = {
    coredns    = {}
    kube-proxy = {}
    vpc-cni    = {}
  }

  tags = {
    Project     = var.project_name
    Environment = var.environment
  }
}
