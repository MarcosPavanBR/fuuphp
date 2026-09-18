<script>
  import LoginScreen from './LoginScreen.svelte';
  import OtpScreen from './OtpScreen.svelte';
  import SignupScreen from './SignupScreen.svelte';
  import { toastr } from '../toastr.js';
  import { signupPending, finishSignup } from '../session.svelte.js';

  // Orquestra a Fase 10 do cliente: 10.1 -> 10.2 -> 10.3.
  //
  // O cadastro (10.3) só aparece pra quem acabou de criar conta pelo OTP: o
  // usuário já existe no banco com nome e telefone verificados, e esta tela
  // completa o que falta (CPF, e-mail, consentimentos). Quem já tinha conta
  // entra direto -- pedir CPF de novo a cada login seria pedir o mesmo dado
  // duas vezes, o oposto do "mínimo necessário" da tela.
  //
  // Substitui o QuickLogin provisório; é usado tanto na aba que exige login
  // quanto dentro do modal de item (3.2), por isso não assume tela cheia.
  let { onSuccess, onPartnerLogin } = $props();

  let step = $state('login');
  let contact = $state(null);
  let purpose = $state('login');

  function codeSent(info) {
    contact = info.phone ? { phone: info.phone } : { email: info.email };
    purpose = info.purpose;
    step = 'code';
  }

  function verified() {
    // Conta nova segue pro cadastro (10.3); conta que já existia entra
    // direto -- pedir CPF de novo a cada login seria pedir o mesmo dado
    // duas vezes. Quem sabe qual é o caso é a sessão, não esta tela.
    if (signupPending()) {
      step = 'signup';
    } else {
      toastr.success('Bem-vindo de volta!');
      onSuccess();
    }
  }

  function signupDone() {
    finishSignup();
    onSuccess();
  }
</script>

{#if step === 'login'}
  <LoginScreen
    onCodeSent={codeSent}
    onPartnerLogin={onPartnerLogin ??
      (() => toastr.info('O painel da loja fica em /painel.html — entre com CNPJ e senha.'))}
  />
{:else if step === 'code'}
  <OtpScreen {contact} {purpose} onVerified={verified} onChangeContact={() => (step = 'login')} />
{:else}
  <SignupScreen onDone={signupDone} />
{/if}
